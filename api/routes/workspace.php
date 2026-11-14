<?php
declare(strict_types=1);

function handle_workspace_routes(PDO $pdo, string $method, string $path): bool
{
    /* ==================== TASKS ==================== */

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/tasks$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare(
            "SELECT t.*, u.full_name AS assignee_name, u.username AS assignee_username, u.avatar_color AS assignee_color
             FROM tasks t
             LEFT JOIN users u ON u.id = t.assigned_to
             WHERE t.project_id = ?
             ORDER BY CASE t.priority WHEN 'High' THEN 0 WHEN 'Medium' THEN 1 ELSE 2 END, t.id DESC"
        );
        $stmt->execute([$projectId]);
        $tasks = $stmt->fetchAll();

        $attStmt = $pdo->prepare(
            'SELECT ta.id, ta.original_name, ta.mime_type, ta.file_size, ta.created_at, u.full_name AS uploader_name
             FROM task_attachments ta
             JOIN users u ON u.id = ta.uploaded_by
             WHERE ta.task_id = ?
             ORDER BY ta.id DESC'
        );

        foreach ($tasks as &$t) {
            $t['id'] = (int) $t['id'];
            $t['project_id'] = (int) $t['project_id'];
            $t['assigned_to'] = $t['assigned_to'] !== null ? (int) $t['assigned_to'] : null;
            $t['created_by'] = (int) $t['created_by'];

            $attStmt->execute([$t['id']]);
            $atts = $attStmt->fetchAll();
            foreach ($atts as &$a) {
                $a['id'] = (int) $a['id'];
                $a['file_size'] = (int) $a['file_size'];
            }
            unset($a);
            $t['attachments'] = $atts;
        }
        unset($t);

        json_response(['tasks' => $tasks]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/tasks$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $cleanTitle = trim((string) ($body['title'] ?? ''));
        if (strlen($cleanTitle) < 2) {
            json_response(['error' => 'Task title is required.'], 400);
        }

        $priority = in_array($body['priority'] ?? '', ['Low', 'Medium', 'High'], true) ? $body['priority'] : 'Medium';
        $status = in_array($body['status'] ?? '', ['To Do', 'In Progress', 'Completed'], true) ? $body['status'] : 'To Do';
        $assigneeId = !empty($body['assignedTo']) ? (int) $body['assignedTo'] : null;

        if ($assigneeId !== null) {
            $chk = $pdo->prepare('SELECT id FROM project_members WHERE project_id = ? AND user_id = ?');
            $chk->execute([$projectId, $assigneeId]);
            if (!$chk->fetch()) {
                json_response(['error' => 'Assigned user must belong to this project room.'], 400);
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO tasks (project_id, title, description, assigned_to, priority, start_date, deadline, status, leader_feedback, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $projectId,
            $cleanTitle,
            trim((string) ($body['description'] ?? '')),
            $assigneeId,
            $priority,
            !empty($body['startDate']) ? (string) $body['startDate'] : null,
            !empty($body['deadline']) ? (string) $body['deadline'] : null,
            $status,
            '',
            $user['id'],
        ]);
        $taskId = (int) $pdo->lastInsertId();

        if ($assigneeId !== null && $assigneeId !== $user['id']) {
            notify_user($pdo, $projectId, $assigneeId, 'New Task Assigned', "You were assigned: \"{$cleanTitle}\".");
        }
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} created task \"{$cleanTitle}\"");
        json_response(['taskId' => $taskId], 201);
    }

    if ($method === 'PATCH' && preg_match('#^/projects/(\d+)/tasks/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $taskId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND project_id = ?');
        $stmt->execute([$taskId, $projectId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(['error' => 'Task not found.'], 404);
        }

        $isLeader = $membership['role'] === 'leader';
        $isAssignee = $existing['assigned_to'] !== null && (int) $existing['assigned_to'] === $user['id'];
        if (!$isLeader && !$isAssignee) {
            json_response(['error' => 'You can only update status on tasks assigned to you.'], 403);
        }

        $body = read_json_body();
        $nextStatus = in_array($body['status'] ?? '', ['To Do', 'In Progress', 'Completed'], true)
            ? $body['status']
            : (string) $existing['status'];

        if ($isLeader) {
            $cleanTitle = array_key_exists('title', $body) ? trim((string) $body['title']) : (string) $existing['title'];
            if (strlen($cleanTitle) < 2) {
                json_response(['error' => 'Task title is required.'], 400);
            }
            $nextPriority = in_array($body['priority'] ?? '', ['Low', 'Medium', 'High'], true)
                ? $body['priority']
                : (string) $existing['priority'];
            $nextAssigned = array_key_exists('assignedTo', $body)
                ? (!empty($body['assignedTo']) ? (int) $body['assignedTo'] : null)
                : ($existing['assigned_to'] !== null ? (int) $existing['assigned_to'] : null);
            $nextStart = array_key_exists('startDate', $body)
                ? (!empty($body['startDate']) ? (string) $body['startDate'] : null)
                : $existing['start_date'];
            $nextDeadline = array_key_exists('deadline', $body)
                ? (!empty($body['deadline']) ? (string) $body['deadline'] : null)
                : $existing['deadline'];
            $nextDesc = array_key_exists('description', $body) ? trim((string) $body['description']) : (string) $existing['description'];
            $nextFeedback = array_key_exists('leaderFeedback', $body) ? trim((string) $body['leaderFeedback']) : (string) $existing['leader_feedback'];

            $pdo->prepare(
                'UPDATE tasks SET title = ?, description = ?, assigned_to = ?, priority = ?, start_date = ?, deadline = ?, status = ?, leader_feedback = ?, updated_at = NOW()
                 WHERE id = ? AND project_id = ?'
            )->execute([$cleanTitle, $nextDesc, $nextAssigned, $nextPriority, $nextStart, $nextDeadline, $nextStatus, $nextFeedback, $taskId, $projectId]);
        } else {
            $pdo->prepare('UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ? AND project_id = ?')
                ->execute([$nextStatus, $taskId, $projectId]);
        }

        if ($nextStatus !== $existing['status']) {
            log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} moved \"{$existing['title']}\" to {$nextStatus}");
        }
        json_response(['ok' => true]);
    }

    if ($method === 'DELETE' && preg_match('#^/projects/(\d+)/tasks/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $taskId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $stmt = $pdo->prepare('SELECT title FROM tasks WHERE id = ? AND project_id = ?');
        $stmt->execute([$taskId, $projectId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(['error' => 'Task not found.'], 404);
        }

        $pdo->prepare('DELETE FROM tasks WHERE id = ? AND project_id = ?')->execute([$taskId, $projectId]);
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} deleted task \"{$existing['title']}\"");
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/tasks/(\d+)/attachments$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $taskId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT id, title, assigned_to FROM tasks WHERE id = ? AND project_id = ?');
        $stmt->execute([$taskId, $projectId]);
        $task = $stmt->fetch();
        if (!$task) {
            json_response(['error' => 'Task not found.'], 404);
        }
        if ($membership['role'] !== 'leader' && (int) $task['assigned_to'] !== $user['id']) {
            json_response(['error' => 'Only the assignee or leader can upload progress evidence.'], 403);
        }

        $uploaded = handle_upload('file', 'attachments', false);
        if (!$uploaded) {
            json_response(['error' => 'Please select a file to upload.'], 400);
        }

        $pdo->prepare(
            'INSERT INTO task_attachments (task_id, project_id, uploaded_by, original_name, stored_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $taskId,
            $projectId,
            $user['id'],
            $uploaded['original_name'],
            $uploaded['stored_name'],
            $uploaded['mime_type'],
            $uploaded['size'],
        ]);

        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} uploaded progress evidence on \"{$task['title']}\"");
        json_response(['ok' => true], 201);
    }

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/files/task-attachment/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $attId = (int) $m[2];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT stored_name, original_name, mime_type FROM task_attachments WHERE id = ? AND project_id = ?');
        $stmt->execute([$attId, $projectId]);
        $att = $stmt->fetch();
        if (!$att) {
            json_response(['error' => 'File not found.'], 404);
        }
        $filePath = resolve_safe_upload_path('attachments', (string) $att['stored_name']);
        if (!$filePath) {
            json_response(['error' => 'Stored file missing.'], 404);
        }
        stream_protected_file($filePath, (string) $att['mime_type'], (string) $att['original_name']);
    }

    /* ==================== MEETINGS & NOTICE BOARD ==================== */

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/meetings$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $mStmt = $pdo->prepare(
            'SELECT m.*, u.full_name AS creator_name
             FROM meetings m
             JOIN users u ON u.id = m.created_by
             WHERE m.project_id = ?
             ORDER BY m.meeting_date DESC, m.start_time DESC'
        );
        $mStmt->execute([$projectId]);
        $meetings = $mStmt->fetchAll();

        $attStmt = $pdo->prepare(
            'SELECT ma.user_id, ma.status, u.full_name, u.username
             FROM meeting_attendance ma
             JOIN users u ON u.id = ma.user_id
             WHERE ma.meeting_id = ?'
        );

        foreach ($meetings as &$mt) {
            $mt['id'] = (int) $mt['id'];
            $mt['project_id'] = (int) $mt['project_id'];
            $mt['created_by'] = (int) $mt['created_by'];

            $attStmt->execute([$mt['id']]);
            $attendance = $attStmt->fetchAll();
            $myRsvp = 'Pending';
            foreach ($attendance as &$a) {
                $a['user_id'] = (int) $a['user_id'];
                if ($a['user_id'] === $user['id']) {
                    $myRsvp = $a['status'];
                }
            }
            unset($a);
            $mt['attendance'] = $attendance;
            $mt['my_rsvp'] = $myRsvp;
        }
        unset($mt);

        $aStmt = $pdo->prepare(
            'SELECT a.*, u.full_name AS author_name
             FROM announcements a
             JOIN users u ON u.id = a.created_by
             WHERE a.project_id = ?
             ORDER BY a.id DESC'
        );
        $aStmt->execute([$projectId]);
        $announcements = $aStmt->fetchAll();
        foreach ($announcements as &$an) {
            $an['id'] = (int) $an['id'];
            $an['project_id'] = (int) $an['project_id'];
            $an['created_by'] = (int) $an['created_by'];
        }
        unset($an);

        json_response(['meetings' => $meetings, 'announcements' => $announcements]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/announcements$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $title = trim((string) ($body['title'] ?? ''));
        $content = trim((string) ($body['content'] ?? ''));
        if (strlen($title) < 2 || strlen($content) < 2) {
            json_response(['error' => 'Announcement title and message are required.'], 400);
        }

        $pdo->prepare('INSERT INTO announcements (project_id, title, content, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$projectId, $title, $content, $user['id']]);
        notify_project_members($pdo, $projectId, $user['id'], 'New Announcement', $title);
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} posted announcement \"{$title}\"");
        json_response(['ok' => true], 201);
    }

    if ($method === 'DELETE' && preg_match('#^/projects/(\d+)/announcements/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $annId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $pdo->prepare('DELETE FROM announcements WHERE id = ? AND project_id = ?')->execute([$annId, $projectId]);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/meetings$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $cleanTitle = trim((string) ($body['title'] ?? ''));
        $cleanDate = trim((string) ($body['meetingDate'] ?? ''));
        $cleanTime = trim((string) ($body['startTime'] ?? ''));
        $cleanLoc = trim((string) ($body['location'] ?? ''));
        if ($cleanTitle === '' || $cleanDate === '' || $cleanTime === '' || $cleanLoc === '') {
            json_response(['error' => 'Title, date, start time, and location are required.'], 400);
        }

        $pdo->prepare(
            "INSERT INTO meetings (project_id, title, description, meeting_date, start_time, location, agenda, status, notes, decisions, action_items, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Scheduled', '', '', '', ?)"
        )->execute([
            $projectId,
            $cleanTitle,
            trim((string) ($body['description'] ?? '')),
            $cleanDate,
            $cleanTime,
            $cleanLoc,
            trim((string) ($body['agenda'] ?? '')),
            $user['id'],
        ]);
        $meetingId = (int) $pdo->lastInsertId();

        $mStmt = $pdo->prepare('SELECT user_id FROM project_members WHERE project_id = ?');
        $mStmt->execute([$projectId]);
        $insRsvp = $pdo->prepare('INSERT IGNORE INTO meeting_attendance (meeting_id, user_id, status) VALUES (?, ?, ?)');
        foreach ($mStmt->fetchAll() as $mem) {
            $uid = (int) $mem['user_id'];
            $insRsvp->execute([$meetingId, $uid, $uid === $user['id'] ? 'Going' : 'Pending']);
        }

        notify_project_members($pdo, $projectId, $user['id'], 'Meeting Scheduled', "{$cleanTitle} on {$cleanDate} at {$cleanTime} ({$cleanLoc})");
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} scheduled meeting \"{$cleanTitle}\"");
        json_response(['meetingId' => $meetingId], 201);
    }

    if ($method === 'PATCH' && preg_match('#^/projects/(\d+)/meetings/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $meetingId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $stmt = $pdo->prepare('SELECT * FROM meetings WHERE id = ? AND project_id = ?');
        $stmt->execute([$meetingId, $projectId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(['error' => 'Meeting not found.'], 404);
        }

        $body = read_json_body();
        $nextStatus = in_array($body['status'] ?? '', ['Scheduled', 'Completed', 'Cancelled'], true)
            ? $body['status']
            : (string) $existing['status'];

        $pdo->prepare(
            'UPDATE meetings SET title = ?, description = ?, meeting_date = ?, start_time = ?, location = ?, agenda = ?, status = ?, notes = ?, decisions = ?, action_items = ?
             WHERE id = ? AND project_id = ?'
        )->execute([
            array_key_exists('title', $body) ? trim((string) $body['title']) : (string) $existing['title'],
            array_key_exists('description', $body) ? trim((string) $body['description']) : (string) $existing['description'],
            array_key_exists('meetingDate', $body) ? trim((string) $body['meetingDate']) : (string) $existing['meeting_date'],
            array_key_exists('startTime', $body) ? trim((string) $body['startTime']) : (string) $existing['start_time'],
            array_key_exists('location', $body) ? trim((string) $body['location']) : (string) $existing['location'],
            array_key_exists('agenda', $body) ? trim((string) $body['agenda']) : (string) $existing['agenda'],
            $nextStatus,
            array_key_exists('notes', $body) ? trim((string) $body['notes']) : (string) $existing['notes'],
            array_key_exists('decisions', $body) ? trim((string) $body['decisions']) : (string) $existing['decisions'],
            array_key_exists('actionItems', $body) ? trim((string) $body['actionItems']) : (string) $existing['action_items'],
            $meetingId,
            $projectId,
        ]);

        json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/meetings/(\d+)/rsvp$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $meetingId = (int) $m[2];
        require_project_member($pdo, $user, $projectId);

        $body = read_json_body();
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, ['Going', 'Unable'], true)) {
            json_response(['error' => 'Invalid attendance status.'], 400);
        }

        $chk = $pdo->prepare('SELECT id FROM meetings WHERE id = ? AND project_id = ?');
        $chk->execute([$meetingId, $projectId]);
        if (!$chk->fetch()) {
            json_response(['error' => 'Meeting not found.'], 404);
        }

        $pdo->prepare(
            'INSERT INTO meeting_attendance (meeting_id, user_id, status, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()'
        )->execute([$meetingId, $user['id'], $status]);

        json_response(['ok' => true]);
    }

    /* ==================== HARDWARE COMPONENTS ==================== */

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/components$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT * FROM components WHERE project_id = ? ORDER BY id DESC');
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();

        $components = [];
        foreach ($rows as $c) {
            $req = (int) $c['qty_required'];
            $avail = (int) $c['qty_available'];
            $check = evaluate_component_stock($req, $avail, (string) $c['purchase_status']);
            $components[] = [
                'id' => (int) $c['id'],
                'project_id' => (int) $c['project_id'],
                'name' => $c['name'],
                'category' => $c['category'],
                'description' => $c['description'],
                'qty_required' => $req,
                'qty_available' => $avail,
                'unit_price' => (float) $c['unit_price'],
                'supplier' => $c['supplier'],
                'purchase_status' => $c['purchase_status'],
                'notes' => $c['notes'],
                'updated_at' => $c['updated_at'],
                'shortfall' => $check['shortfall'],
                'effective_status' => $check['effectiveStatus'],
            ];
        }

        json_response(['components' => $components]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/components$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $body = read_json_body();
        $cleanName = trim((string) ($body['name'] ?? ''));
        if (strlen($cleanName) < 2) {
            json_response(['error' => 'Component name is required.'], 400);
        }

        $cleanCategory = trim((string) ($body['category'] ?? 'Other')) ?: 'Other';
        $reqQty = max(1, (int) floor((float) ($body['qtyRequired'] ?? 1)));
        $availQty = max(0, (int) floor((float) ($body['qtyAvailable'] ?? 0)));
        $price = max(0.0, (float) ($body['unitPrice'] ?? 0));
        $status = in_array($body['purchaseStatus'] ?? '', ['Not Purchased', 'Ordered', 'Available'], true)
            ? $body['purchaseStatus']
            : 'Not Purchased';

        $ins = $pdo->prepare(
            'INSERT INTO components (project_id, name, category, description, qty_required, qty_available, unit_price, supplier, purchase_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $projectId,
            $cleanName,
            $cleanCategory,
            trim((string) ($body['description'] ?? '')),
            $reqQty,
            $availQty,
            $price,
            trim((string) ($body['supplier'] ?? '')),
            $status,
            trim((string) ($body['notes'] ?? '')),
        ]);
        $componentId = (int) $pdo->lastInsertId();

        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} added component \"{$cleanName}\"");
        json_response(['componentId' => $componentId], 201);
    }

    if ($method === 'PATCH' && preg_match('#^/projects/(\d+)/components/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $compId = (int) $m[2];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT * FROM components WHERE id = ? AND project_id = ?');
        $stmt->execute([$compId, $projectId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(['error' => 'Component not found.'], 404);
        }

        $body = read_json_body();
        $cleanName = array_key_exists('name', $body) ? trim((string) $body['name']) : (string) $existing['name'];
        if (strlen($cleanName) < 2) {
            json_response(['error' => 'Component name is required.'], 400);
        }

        $nextStatus = in_array($body['purchaseStatus'] ?? '', ['Not Purchased', 'Ordered', 'Available'], true)
            ? $body['purchaseStatus']
            : (string) $existing['purchase_status'];

        $pdo->prepare(
            'UPDATE components SET name = ?, category = ?, description = ?, qty_required = ?, qty_available = ?, unit_price = ?, supplier = ?, purchase_status = ?, notes = ?, updated_at = NOW()
             WHERE id = ? AND project_id = ?'
        )->execute([
            $cleanName,
            array_key_exists('category', $body) ? trim((string) $body['category']) : (string) $existing['category'],
            array_key_exists('description', $body) ? trim((string) $body['description']) : (string) $existing['description'],
            array_key_exists('qtyRequired', $body) ? max(1, (int) floor((float) $body['qtyRequired'])) : (int) $existing['qty_required'],
            array_key_exists('qtyAvailable', $body) ? max(0, (int) floor((float) $body['qtyAvailable'])) : (int) $existing['qty_available'],
            array_key_exists('unitPrice', $body) ? max(0.0, (float) $body['unitPrice']) : (float) $existing['unit_price'],
            array_key_exists('supplier', $body) ? trim((string) $body['supplier']) : (string) $existing['supplier'],
            $nextStatus,
            array_key_exists('notes', $body) ? trim((string) $body['notes']) : (string) $existing['notes'],
            $compId,
            $projectId,
        ]);

        json_response(['ok' => true]);
    }

    if ($method === 'DELETE' && preg_match('#^/projects/(\d+)/components/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $compId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $stmt = $pdo->prepare('SELECT name FROM components WHERE id = ? AND project_id = ?');
        $stmt->execute([$compId, $projectId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(['error' => 'Component not found.'], 404);
        }

        $pdo->prepare('DELETE FROM components WHERE id = ? AND project_id = ?')->execute([$compId, $projectId]);
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} removed component \"{$existing['name']}\"");
        json_response(['ok' => true]);
    }

    return false;
}
