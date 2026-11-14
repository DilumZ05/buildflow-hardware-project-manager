<?php
declare(strict_types=1);

function handle_project_routes(PDO $pdo, string $method, string $path): bool
{
    // List user's project rooms
    if ($method === 'GET' && $path === '/projects') {
        $user = require_auth($pdo);
        $stmt = $pdo->prepare(
            "SELECT p.id, p.room_code, p.room_name, p.project_name, p.description, p.max_members,
                    p.planned_budget, p.currency, p.expected_completion_date, p.leader_id, p.created_at,
                    pm.role AS my_role, u.full_name AS leader_name
             FROM project_members pm
             JOIN projects p ON p.id = pm.project_id
             JOIN users u ON u.id = p.leader_id
             WHERE pm.user_id = ?
             ORDER BY p.created_at DESC"
        );
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();

        $mStmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.username, u.avatar_color, pm.role
             FROM project_members pm
             JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = ?
             ORDER BY CASE WHEN pm.role = 'leader' THEN 0 ELSE 1 END, pm.joined_at ASC"
        );
        $tStmt = $pdo->prepare('SELECT status FROM tasks WHERE project_id = ?');

        $projects = [];
        foreach ($rows as $p) {
            $pid = (int) $p['id'];
            $mStmt->execute([$pid]);
            $members = $mStmt->fetchAll();
            foreach ($members as &$m) {
                $m['id'] = (int) $m['id'];
            }
            unset($m);

            $tStmt->execute([$pid]);
            $tasks = $tStmt->fetchAll();
            $completed = 0;
            foreach ($tasks as $t) {
                if ($t['status'] === 'Completed') {
                    $completed++;
                }
            }

            $projects[] = [
                'id' => $pid,
                'room_code' => $p['room_code'],
                'room_name' => $p['room_name'],
                'project_name' => $p['project_name'],
                'description' => $p['description'],
                'max_members' => (int) $p['max_members'],
                'planned_budget' => (float) $p['planned_budget'],
                'currency' => normalize_currency($p['currency']),
                'expected_completion_date' => $p['expected_completion_date'],
                'leader_id' => (int) $p['leader_id'],
                'created_at' => $p['created_at'],
                'my_role' => $p['my_role'],
                'leader_name' => $p['leader_name'],
                'member_count' => count($members),
                'members' => $members,
                'completion_percentage' => calculate_completion_percentage($tasks),
                'total_tasks' => count($tasks),
                'completed_tasks' => $completed,
            ];
        }

        json_response(['projects' => $projects]);
    }

    // Create project room
    if ($method === 'POST' && $path === '/projects') {
        $user = require_auth($pdo);
        $body = read_json_body();

        $cleanRoomName = trim((string) ($body['roomName'] ?? ''));
        $cleanProjectName = trim((string) ($body['projectName'] ?? ''));
        $pass = (string) ($body['roomPassword'] ?? '');
        $confirm = (string) ($body['confirmRoomPassword'] ?? '');
        $cleanDesc = trim((string) ($body['description'] ?? ''));
        $maxMem = (int) ($body['maxMembers'] ?? 0);
        $budget = (float) ($body['plannedBudget'] ?? 0);
        $projectCurrency = normalize_currency($body['currency'] ?? 'LKR');
        $completionDate = !empty($body['expectedCompletionDate']) ? trim((string) $body['expectedCompletionDate']) : null;

        if (strlen($cleanRoomName) < 3) {
            json_response(['error' => 'Project room name must be at least 3 characters.'], 400);
        }
        if (strlen($cleanProjectName) < 3) {
            json_response(['error' => 'Actual project name must be at least 3 characters.'], 400);
        }
        if (strlen($pass) < 4) {
            json_response(['error' => 'Room password must be at least 4 characters.'], 400);
        }
        if ($pass !== $confirm) {
            json_response(['error' => 'Room passwords do not match.'], 400);
        }
        if ($maxMem < 2 || $maxMem > 5) {
            json_response(['error' => 'Maximum team size must be between 2 and 5 members.'], 400);
        }
        if (!is_finite($budget) || $budget < 0) {
            json_response(['error' => 'Planned budget must be 0 or greater.'], 400);
        }

        $ex = $pdo->prepare('SELECT id FROM projects WHERE LOWER(room_name) = LOWER(?) LIMIT 1');
        $ex->execute([$cleanRoomName]);
        if ($ex->fetch()) {
            json_response(['error' => 'A project room with that name already exists.'], 409);
        }

        $roomCode = generate_room_code($pdo);
        $passHash = hash_app_password($pass);

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO projects (room_code, room_name, project_name, room_password_hash, description, max_members, planned_budget, currency, expected_completion_date, leader_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $roomCode,
                $cleanRoomName,
                $cleanProjectName,
                $passHash,
                $cleanDesc,
                $maxMem,
                $budget,
                $projectCurrency,
                $completionDate,
                $user['id'],
            ]);
            $createdProjectId = (int) $pdo->lastInsertId();

            $pm = $pdo->prepare(
                'INSERT INTO project_members (project_id, user_id, role, responsibilities) VALUES (?, ?, ?, ?)'
            );
            $pm->execute([$createdProjectId, $user['id'], 'leader', 'Project Lead & Coordination']);

            log_activity($pdo, $createdProjectId, $user['id'], "{$user['full_name']} created project room \"{$cleanRoomName}\"");
            $pdo->commit();

            json_response(['projectId' => $createdProjectId, 'roomCode' => $roomCode], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Join existing project room
    if ($method === 'POST' && $path === '/projects/join') {
        $user = require_auth($pdo);
        $body = read_json_body();
        $cleanId = trim((string) ($body['roomIdentifier'] ?? ''));
        $pass = (string) ($body['roomPassword'] ?? '');

        if ($cleanId === '' || $pass === '') {
            json_response(['error' => 'Room name (or Room ID) and password are required.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT id, room_code, room_name, room_password_hash, max_members
             FROM projects
             WHERE LOWER(room_name) = LOWER(?) OR UPPER(room_code) = UPPER(?) LIMIT 1'
        );
        $stmt->execute([$cleanId, $cleanId]);
        $project = $stmt->fetch();

        if (!$project || !verify_app_password($pass, (string) $project['room_password_hash'])) {
            json_response(['error' => 'Invalid room name/ID or room password.'], 401);
        }

        $projectId = (int) $project['id'];
        $mCheck = $pdo->prepare('SELECT id FROM project_members WHERE project_id = ? AND user_id = ?');
        $mCheck->execute([$projectId, $user['id']]);
        if ($mCheck->fetch()) {
            json_response(['error' => 'You are already a member of this project room.'], 400);
        }

        $cStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM project_members WHERE project_id = ?');
        $cStmt->execute([$projectId]);
        $cnt = (int) $cStmt->fetch()['cnt'];
        if ($cnt >= (int) $project['max_members']) {
            json_response(['error' => 'This project room has reached its maximum team size.'], 400);
        }

        $pdo->prepare('INSERT INTO project_members (project_id, user_id, role, responsibilities) VALUES (?, ?, ?, ?)')
            ->execute([$projectId, $user['id'], 'member', 'Team Member']);

        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} joined the project room");
        notify_project_members($pdo, $projectId, $user['id'], 'New Team Member', "{$user['full_name']} joined {$project['room_name']}.");

        json_response(['projectId' => $projectId, 'roomName' => $project['room_name']]);
    }

    // Overview
    if ($method === 'GET' && preg_match('#^/projects/(\d+)/overview$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);

        $pStmt = $pdo->prepare(
            'SELECT p.id, p.room_code, p.room_name, p.project_name, p.description, p.max_members,
                    p.planned_budget, p.currency, p.expected_completion_date, p.leader_id, p.created_at,
                    u.full_name AS leader_name
             FROM projects p
             JOIN users u ON u.id = p.leader_id
             WHERE p.id = ?'
        );
        $pStmt->execute([$projectId]);
        $project = $pStmt->fetch();

        $mStmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.username, u.email, u.avatar_color, pm.role, pm.responsibilities, pm.joined_at
             FROM project_members pm
             JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = ?
             ORDER BY CASE WHEN pm.role = 'leader' THEN 0 ELSE 1 END, pm.joined_at ASC"
        );
        $mStmt->execute([$projectId]);
        $members = $mStmt->fetchAll();
        foreach ($members as &$mem) {
            $mem['id'] = (int) $mem['id'];
        }
        unset($mem);

        $tStmt = $pdo->prepare('SELECT id, assigned_to, status, deadline FROM tasks WHERE project_id = ?');
        $tStmt->execute([$projectId]);
        $tasks = $tStmt->fetchAll();

        $cStmt = $pdo->prepare('SELECT id, status FROM contribution_requests WHERE project_id = ?');
        $cStmt->execute([$projectId]);
        $contributions = $cStmt->fetchAll();

        $payStmt = $pdo->prepare('SELECT contribution_id, expected_amount, submitted_amount, status FROM payments WHERE project_id = ?');
        $payStmt->execute([$projectId]);
        $payments = $payStmt->fetchAll();

        $expStmt = $pdo->prepare('SELECT amount, status FROM expenses WHERE project_id = ?');
        $expStmt->execute([$projectId]);
        $expenses = $expStmt->fetchAll();

        $finances = calculate_project_finances(
            (float) ($project['planned_budget'] ?? 0),
            $contributions,
            $payments,
            $expenses
        );

        $meetStmt = $pdo->prepare(
            "SELECT m.*, u.full_name AS creator_name
             FROM meetings m
             JOIN users u ON u.id = m.created_by
             WHERE m.project_id = ? AND m.status = 'Scheduled'
             ORDER BY m.meeting_date ASC, m.start_time ASC
             LIMIT 4"
        );
        $meetStmt->execute([$projectId]);
        $upcomingMeetings = $meetStmt->fetchAll();
        foreach ($upcomingMeetings as &$um) {
            $um['id'] = (int) $um['id'];
            $um['project_id'] = (int) $um['project_id'];
            $um['created_by'] = (int) $um['created_by'];
        }
        unset($um);

        $actStmt = $pdo->prepare(
            'SELECT a.id, a.action_text, a.created_at, u.full_name AS actor_name
             FROM activity_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.project_id = ?
             ORDER BY a.id DESC
             LIMIT 12'
        );
        $actStmt->execute([$projectId]);
        $activities = $actStmt->fetchAll();
        foreach ($activities as &$act) {
            $act['id'] = (int) $act['id'];
        }
        unset($act);

        $notStmt = $pdo->prepare(
            'SELECT id, title, message, is_read, created_at
             FROM notifications
             WHERE project_id = ? AND user_id = ?
             ORDER BY id DESC
             LIMIT 20'
        );
        $notStmt->execute([$projectId, $user['id']]);
        $notifications = $notStmt->fetchAll();
        foreach ($notifications as &$n) {
            $n['id'] = (int) $n['id'];
            $n['is_read'] = (int) $n['is_read'];
        }
        unset($n);

        $completedCount = 0;
        $inProgressCount = 0;
        $todoCount = 0;
        foreach ($tasks as $t) {
            if ($t['status'] === 'Completed') $completedCount++;
            elseif ($t['status'] === 'In Progress') $inProgressCount++;
            else $todoCount++;
        }

        json_response([
            'project' => [
                'id' => (int) $project['id'],
                'room_code' => $project['room_code'],
                'room_name' => $project['room_name'],
                'project_name' => $project['project_name'],
                'description' => $project['description'],
                'max_members' => (int) $project['max_members'],
                'planned_budget' => (float) $project['planned_budget'],
                'currency' => normalize_currency($project['currency']),
                'expected_completion_date' => $project['expected_completion_date'],
                'leader_id' => (int) $project['leader_id'],
                'created_at' => $project['created_at'],
                'leader_name' => $project['leader_name'],
                'my_role' => $membership['role'],
                'member_count' => count($members),
            ],
            'members' => $members,
            'stats' => [
                'total_members' => count($members),
                'total_tasks' => count($tasks),
                'completed_tasks' => $completedCount,
                'in_progress_tasks' => $inProgressCount,
                'todo_tasks' => $todoCount,
                'completion_percentage' => calculate_completion_percentage($tasks),
            ],
            'finances' => $finances,
            'upcomingMeetings' => $upcomingMeetings,
            'activities' => $activities,
            'notifications' => $notifications,
        ]);
    }

    // Mark notifications read
    if ($method === 'POST' && preg_match('#^/projects/(\d+)/notifications/read$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE project_id = ? AND user_id = ?')
            ->execute([$projectId, $user['id']]);
        json_response(['ok' => true]);
    }

    // Team list
    if ($method === 'GET' && preg_match('#^/projects/(\d+)/team$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.username, u.email, u.avatar_color, pm.role, pm.responsibilities, pm.joined_at
             FROM project_members pm
             JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = ?
             ORDER BY CASE WHEN pm.role = 'leader' THEN 0 ELSE 1 END, pm.joined_at ASC"
        );
        $stmt->execute([$projectId]);
        $members = $stmt->fetchAll();

        $tStmt = $pdo->prepare(
            "SELECT COUNT(*) AS assigned_total,
                    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS assigned_completed
             FROM tasks WHERE project_id = ? AND assigned_to = ?"
        );

        foreach ($members as &$mem) {
            $mem['id'] = (int) $mem['id'];
            $tStmt->execute([$projectId, $mem['id']]);
            $s = $tStmt->fetch();
            $mem['assigned_total'] = (int) ($s['assigned_total'] ?? 0);
            $mem['assigned_completed'] = (int) ($s['assigned_completed'] ?? 0);
        }
        unset($mem);

        json_response(['members' => $members]);
    }

    // Update member responsibilities
    if ($method === 'PATCH' && preg_match('#^/projects/(\d+)/team/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $targetUserId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $responsibilities = trim((string) ($body['responsibilities'] ?? ''));
        $pdo->prepare('UPDATE project_members SET responsibilities = ? WHERE project_id = ? AND user_id = ?')
            ->execute([$responsibilities, $projectId, $targetUserId]);
        json_response(['ok' => true]);
    }

    // Remove member
    if ($method === 'DELETE' && preg_match('#^/projects/(\d+)/team/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $targetUserId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        if ($targetUserId === $user['id']) {
            json_response(['error' => 'Use Transfer Leadership or Leave Room instead.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT u.full_name FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = ? AND pm.user_id = ?'
        );
        $stmt->execute([$projectId, $targetUserId]);
        $member = $stmt->fetch();
        if (!$member) {
            json_response(['error' => 'Member not found in this project.'], 404);
        }

        $pdo->prepare('DELETE FROM project_members WHERE project_id = ? AND user_id = ?')
            ->execute([$projectId, $targetUserId]);
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} removed {$member['full_name']} from the team");
        json_response(['ok' => true]);
    }

    // Transfer leadership
    if ($method === 'POST' && preg_match('#^/projects/(\d+)/transfer-leadership$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $newLeaderId = (int) ($body['newLeaderId'] ?? 0);
        if ($newLeaderId <= 0 || $newLeaderId === $user['id']) {
            json_response(['error' => 'Select another team member to transfer leadership.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT u.full_name FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = ? AND pm.user_id = ?'
        );
        $stmt->execute([$projectId, $newLeaderId]);
        $target = $stmt->fetch();
        if (!$target) {
            json_response(['error' => 'Selected user is not a member of this project room.'], 404);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE project_members SET role = ? WHERE project_id = ? AND user_id = ?')
                ->execute(['member', $projectId, $user['id']]);
            $pdo->prepare('UPDATE project_members SET role = ? WHERE project_id = ? AND user_id = ?')
                ->execute(['leader', $projectId, $newLeaderId]);
            $pdo->prepare('UPDATE projects SET leader_id = ? WHERE id = ?')
                ->execute([$newLeaderId, $projectId]);
            log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} transferred project leadership to {$target['full_name']}");
            notify_user($pdo, $projectId, $newLeaderId, 'Leadership Transferred', 'You are now the Project Leader for this room.');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_response(['ok' => true]);
    }

    // Leave project room
    if ($method === 'POST' && preg_match('#^/projects/(\d+)/leave$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);

        $cStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM project_members WHERE project_id = ?');
        $cStmt->execute([$projectId]);
        $totalMembers = (int) $cStmt->fetch()['cnt'];

        if ($membership['role'] === 'leader' && $totalMembers > 1) {
            json_response(['error' => 'Project leader must transfer leadership to another member before leaving.'], 400);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM project_members WHERE project_id = ? AND user_id = ?')
                ->execute([$projectId, $user['id']]);
            if ($totalMembers <= 1) {
                $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);
            } else {
                log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} left the project room");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_response(['ok' => true]);
    }

    // Update currency
    if ($method === 'PATCH' && preg_match('#^/projects/(\d+)/currency$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $nextCurrency = normalize_currency($body['currency'] ?? 'LKR');
        $pdo->prepare('UPDATE projects SET currency = ? WHERE id = ?')->execute([$nextCurrency, $projectId]);
        $label = $nextCurrency === 'USD' ? 'US Dollars (USD)' : 'Sri Lankan Rupees (LKR)';
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} switched project currency to {$label}");
        json_response(['currency' => $nextCurrency]);
    }

    // Update settings
    if ($method === 'PATCH' && preg_match('#^/projects/(\d+)/settings$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $cleanProjectName = trim((string) ($body['projectName'] ?? ''));
        $cleanDesc = trim((string) ($body['description'] ?? ''));
        $maxMem = (int) ($body['maxMembers'] ?? 0);
        $budget = (float) ($body['plannedBudget'] ?? 0);
        $nextCurrency = array_key_exists('currency', $body) ? normalize_currency($body['currency']) : null;
        $completionDate = !empty($body['expectedCompletionDate']) ? trim((string) $body['expectedCompletionDate']) : null;
        $newRoomPassword = (string) ($body['newRoomPassword'] ?? '');

        if (strlen($cleanProjectName) < 3) {
            json_response(['error' => 'Project name must be at least 3 characters.'], 400);
        }

        $cStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM project_members WHERE project_id = ?');
        $cStmt->execute([$projectId]);
        $memberCount = (int) $cStmt->fetch()['cnt'];
        $minAllowed = max(2, $memberCount);
        if ($maxMem < $minAllowed || $maxMem > 5) {
            json_response(['error' => "Max team members must be between {$minAllowed} and 5."], 400);
        }
        if (!is_finite($budget) || $budget < 0) {
            json_response(['error' => 'Planned budget must be 0 or greater.'], 400);
        }

        if ($nextCurrency !== null) {
            $pdo->prepare(
                'UPDATE projects SET project_name = ?, description = ?, max_members = ?, planned_budget = ?, currency = ?, expected_completion_date = ? WHERE id = ?'
            )->execute([$cleanProjectName, $cleanDesc, $maxMem, $budget, $nextCurrency, $completionDate, $projectId]);
        } else {
            $pdo->prepare(
                'UPDATE projects SET project_name = ?, description = ?, max_members = ?, planned_budget = ?, expected_completion_date = ? WHERE id = ?'
            )->execute([$cleanProjectName, $cleanDesc, $maxMem, $budget, $completionDate, $projectId]);
        }

        if (trim($newRoomPassword) !== '') {
            if (strlen($newRoomPassword) < 4) {
                json_response(['error' => 'New room password must be at least 4 characters.'], 400);
            }
            $pdo->prepare('UPDATE projects SET room_password_hash = ? WHERE id = ?')
                ->execute([hash_app_password($newRoomPassword), $projectId]);
        }

        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} updated project settings");
        json_response(['ok' => true]);
    }

    // Delete project room
    if ($method === 'DELETE' && preg_match('#^/projects/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);
        json_response(['ok' => true]);
    }

    return false;
}
