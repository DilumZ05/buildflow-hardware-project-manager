<?php
declare(strict_types=1);

function handle_budget_and_collab_routes(PDO $pdo, string $method, string $path): bool
{
    /* ==================== BUDGET & FINANCIAL MANAGEMENT ==================== */

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/budget$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);
        $isLeader = $membership['role'] === 'leader';

        $pStmt = $pdo->prepare('SELECT planned_budget, currency FROM projects WHERE id = ?');
        $pStmt->execute([$projectId]);
        $project = $pStmt->fetch();

        $cStmt = $pdo->prepare(
            'SELECT cr.*, u.full_name AS creator_name
             FROM contribution_requests cr
             JOIN users u ON u.id = cr.created_by
             WHERE cr.project_id = ?
             ORDER BY cr.id DESC'
        );
        $cStmt->execute([$projectId]);
        $contributions = $cStmt->fetchAll();
        foreach ($contributions as &$c) {
            $c['id'] = (int) $c['id'];
            $c['project_id'] = (int) $c['project_id'];
            $c['amount'] = (float) $c['amount'];
            $c['created_by'] = (int) $c['created_by'];
        }
        unset($c);

        $payStmt = $pdo->prepare(
            'SELECT p.*, u.full_name AS member_name, u.username AS member_username, u.avatar_color AS member_color,
                    pm.role AS member_role, cr.title AS contribution_title
             FROM payments p
             JOIN users u ON u.id = p.user_id
             JOIN project_members pm ON pm.project_id = p.project_id AND pm.user_id = p.user_id
             JOIN contribution_requests cr ON cr.id = p.contribution_id
             WHERE p.project_id = ?
             ORDER BY p.id DESC'
        );
        $payStmt->execute([$projectId]);
        $payments = $payStmt->fetchAll();

        $sanitizedPayments = [];
        foreach ($payments as &$p) {
            $p['id'] = (int) $p['id'];
            $p['contribution_id'] = (int) $p['contribution_id'];
            $p['project_id'] = (int) $p['project_id'];
            $p['user_id'] = (int) $p['user_id'];
            $p['expected_amount'] = (float) $p['expected_amount'];
            $p['submitted_amount'] = $p['submitted_amount'] !== null ? (float) $p['submitted_amount'] : null;
            $p['verified_by'] = $p['verified_by'] !== null ? (int) $p['verified_by'] : null;

            $isOwner = $p['user_id'] === $user['id'];
            $isLeaderPayment = $p['member_role'] === 'leader';
            if (!$isLeader && !$isOwner && !$isLeaderPayment) {
                $copy = $p;
                $copy['receipt_stored_name'] = null;
                $copy['receipt_original_name'] = !empty($p['receipt_original_name']) ? 'Protected Receipt' : null;
                $sanitizedPayments[] = $copy;
            } else {
                $sanitizedPayments[] = $p;
            }
        }
        unset($p);

        $eStmt = $pdo->prepare(
            'SELECT e.*, u.full_name AS purchaser_name
             FROM expenses e
             JOIN users u ON u.id = e.purchased_by
             WHERE e.project_id = ?
             ORDER BY e.id DESC'
        );
        $eStmt->execute([$projectId]);
        $expenses = $eStmt->fetchAll();
        foreach ($expenses as &$e) {
            $e['id'] = (int) $e['id'];
            $e['project_id'] = (int) $e['project_id'];
            $e['amount'] = (float) $e['amount'];
            $e['purchased_by'] = (int) $e['purchased_by'];
            $e['reviewed_by'] = $e['reviewed_by'] !== null ? (int) $e['reviewed_by'] : null;
        }
        unset($e);

        $finances = calculate_project_finances(
            (float) ($project['planned_budget'] ?? 0),
            $contributions,
            $payments,
            $expenses
        );

        json_response([
            'currency' => normalize_currency($project['currency'] ?? 'LKR'),
            'finances' => $finances,
            'contributions' => $contributions,
            'payments' => $sanitizedPayments,
            'expenses' => $expenses,
        ]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/contributions$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $cleanTitle = trim((string) ($body['title'] ?? ''));
        $numericAmount = (float) ($body['amount'] ?? 0);
        if (strlen($cleanTitle) < 2) {
            json_response(['error' => 'Contribution request title is required.'], 400);
        }
        if (!is_finite($numericAmount) || $numericAmount <= 0) {
            json_response(['error' => 'Contribution amount must be greater than zero.'], 400);
        }

        $mStmt = $pdo->prepare('SELECT user_id, role FROM project_members WHERE project_id = ?');
        $mStmt->execute([$projectId]);
        $members = $mStmt->fetchAll();

        $targetMode = (string) ($body['targetMode'] ?? 'all');
        $selectedIds = $body['selectedMemberIds'] ?? [];
        $targets = [];

        if ($targetMode === 'selected' && is_array($selectedIds)) {
            $validSet = [];
            foreach ($members as $mem) {
                $validSet[(int) $mem['user_id']] = true;
            }
            foreach ($selectedIds as $sid) {
                $uid = (int) $sid;
                if (isset($validSet[$uid])) {
                    $targets[] = $uid;
                }
            }
            $targets = array_values(array_unique($targets));
        } elseif ($targetMode === 'all_including_leader' || $targetMode === 'everyone') {
            foreach ($members as $mem) {
                $targets[] = (int) $mem['user_id'];
            }
        } else {
            foreach ($members as $mem) {
                if ($mem['role'] !== 'leader') {
                    $targets[] = (int) $mem['user_id'];
                }
            }
            if (count($targets) === 0) {
                foreach ($members as $mem) {
                    $targets[] = (int) $mem['user_id'];
                }
            }
        }

        if (count($targets) === 0) {
            json_response(['error' => 'Select at least one team member for this contribution request.'], 400);
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                "INSERT INTO contribution_requests (project_id, title, description, amount, instructions, deadline, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'Active', ?)"
            );
            $ins->execute([
                $projectId,
                $cleanTitle,
                trim((string) ($body['description'] ?? '')),
                $numericAmount,
                trim((string) ($body['instructions'] ?? '')),
                !empty($body['deadline']) ? (string) $body['deadline'] : null,
                $user['id'],
            ]);
            $contributionId = (int) $pdo->lastInsertId();

            $pStmt = $pdo->prepare('SELECT currency FROM projects WHERE id = ?');
            $pStmt->execute([$projectId]);
            $projCurrency = normalize_currency($pStmt->fetch()['currency'] ?? 'LKR');
            $formattedAmt = format_money($numericAmount, $projCurrency);

            $payIns = $pdo->prepare(
                "INSERT INTO payments (contribution_id, project_id, user_id, expected_amount, status, rejection_reason)
                 VALUES (?, ?, ?, ?, 'Not Submitted', '')"
            );
            foreach ($targets as $uid) {
                $payIns->execute([$contributionId, $projectId, $uid, $numericAmount]);
                if ($uid !== $user['id']) {
                    notify_user($pdo, $projectId, $uid, 'Contribution Request', "{$cleanTitle}: {$formattedAmt} requested.");
                }
            }

            log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} created contribution request \"{$cleanTitle}\" ({$formattedAmt})");
            $pdo->commit();

            json_response(['contributionId' => $contributionId], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    if ($method === 'PATCH' && preg_match('#^/projects/(\d+)/contributions/(\d+)/archive$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $contribId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $pdo->prepare("UPDATE contribution_requests SET status = 'Archived' WHERE id = ? AND project_id = ?")
            ->execute([$contribId, $projectId]);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/payments/(\d+)/submit$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $paymentId = (int) $m[2];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT id, user_id, status, expected_amount FROM payments WHERE id = ? AND project_id = ?');
        $stmt->execute([$paymentId, $projectId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            json_response(['error' => 'Payment record not found.'], 404);
        }
        if ((int) $payment['user_id'] !== $user['id']) {
            json_response(['error' => 'You can only submit receipts for your own contributions.'], 403);
        }
        if ($payment['status'] === 'Verified') {
            json_response(['error' => 'This payment is already verified.'], 400);
        }

        $submittedAmount = (float) ($_POST['submittedAmount'] ?? $payment['expected_amount']);
        if (!is_finite($submittedAmount) || $submittedAmount <= 0) {
            json_response(['error' => 'Submitted amount must be greater than zero.'], 400);
        }

        $uploaded = handle_upload('receipt', 'receipts', true);
        if (!$uploaded) {
            json_response(['error' => 'Please attach a receipt image (JPG/PNG) or PDF.'], 400);
        }

        $pdo->prepare(
            "UPDATE payments
             SET submitted_amount = ?, receipt_stored_name = ?, receipt_original_name = ?, receipt_mime = ?,
                 status = 'Submitted', rejection_reason = '', submitted_at = NOW()
             WHERE id = ? AND project_id = ?"
        )->execute([
            $submittedAmount,
            $uploaded['stored_name'],
            $uploaded['original_name'],
            $uploaded['mime_type'],
            $paymentId,
            $projectId,
        ]);

        $pStmt = $pdo->prepare('SELECT leader_id, currency FROM projects WHERE id = ?');
        $pStmt->execute([$projectId]);
        $proj = $pStmt->fetch();
        $formattedAmt = format_money($submittedAmount, normalize_currency($proj['currency'] ?? 'LKR'));

        if ((int) $proj['leader_id'] !== $user['id']) {
            notify_user(
                $pdo,
                $projectId,
                (int) $proj['leader_id'],
                'Receipt Submitted',
                "{$user['full_name']} uploaded a payment receipt ({$formattedAmt}) for verification."
            );
        }
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} submitted a payment receipt ({$formattedAmt})");
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/files/receipt/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $paymentId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare(
            'SELECT p.user_id, p.receipt_stored_name, p.receipt_original_name, p.receipt_mime, pm.role AS member_role
             FROM payments p
             JOIN project_members pm ON pm.project_id = p.project_id AND pm.user_id = p.user_id
             WHERE p.id = ? AND p.project_id = ?'
        );
        $stmt->execute([$paymentId, $projectId]);
        $payment = $stmt->fetch();

        if (!$payment || empty($payment['receipt_stored_name'])) {
            json_response(['error' => 'Receipt not found.'], 404);
        }

        $isLeader = $membership['role'] === 'leader';
        $isOwner = (int) $payment['user_id'] === $user['id'];
        $isLeaderPayment = $payment['member_role'] === 'leader';
        if (!$isLeader && !$isOwner && !$isLeaderPayment) {
            json_response(['error' => 'Private payment receipts can only be viewed by the project leader or owner.'], 403);
        }

        $filePath = resolve_safe_upload_path('receipts', (string) $payment['receipt_stored_name']);
        if (!$filePath) {
            json_response(['error' => 'Receipt file missing on disk.'], 404);
        }
        stream_protected_file(
            $filePath,
            (string) ($payment['receipt_mime'] ?: 'application/octet-stream'),
            (string) ($payment['receipt_original_name'] ?: 'receipt')
        );
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/payments/(\d+)/review$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $paymentId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);

        $body = read_json_body();
        $decision = (string) ($body['decision'] ?? '');
        $rejectionReason = trim((string) ($body['rejectionReason'] ?? ''));

        if (!in_array($decision, ['verify', 'reject'], true)) {
            json_response(['error' => 'Decision must be verify or reject.'], 400);
        }
        if ($decision === 'reject' && strlen($rejectionReason) < 2) {
            json_response(['error' => 'Please provide a reason when rejecting a payment.'], 400);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT p.*, u.full_name AS member_name, pm.role AS member_role
                 FROM payments p
                 JOIN users u ON u.id = p.user_id
                 JOIN project_members pm ON pm.project_id = p.project_id AND pm.user_id = p.user_id
                 WHERE p.id = ? AND p.project_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$paymentId, $projectId]);
            $payment = $stmt->fetch();

            if (!$payment) {
                $pdo->rollBack();
                json_response(['error' => 'Payment record not found.'], 404);
            }

            $check = can_verify_payment(
                $user['id'],
                $membership['role'],
                (int) $payment['user_id'],
                (string) $payment['member_role'],
                (string) $payment['status']
            );
            if (!$check['allowed']) {
                $pdo->rollBack();
                $isRoleOrSelf = ($user['id'] === (int) $payment['user_id']) || ($membership['role'] !== 'leader' && $payment['member_role'] !== 'leader');
                json_response(['error' => $check['reason']], $isRoleOrSelf ? 403 : 400);
            }

            if ($decision === 'verify') {
                $pdo->prepare(
                    "UPDATE payments SET status = 'Verified', verified_at = NOW(), verified_by = ?, rejection_reason = ''
                     WHERE id = ? AND project_id = ? AND status = 'Submitted'"
                )->execute([$user['id'], $paymentId, $projectId]);

                $pStmt = $pdo->prepare('SELECT currency FROM projects WHERE id = ?');
                $pStmt->execute([$projectId]);
                $projCurrency = normalize_currency($pStmt->fetch()['currency'] ?? 'LKR');
                $amt = format_money((float) ($payment['submitted_amount'] ?? $payment['expected_amount']), $projCurrency);

                notify_user($pdo, $projectId, (int) $payment['user_id'], 'Payment Verified', "Your payment of {$amt} has been verified.");
                log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} verified {$payment['member_name']}'s payment ({$amt})");
            } else {
                $pdo->prepare(
                    "UPDATE payments SET status = 'Rejected', rejection_reason = ?
                     WHERE id = ? AND project_id = ? AND status = 'Submitted'"
                )->execute([$rejectionReason, $paymentId, $projectId]);

                notify_user($pdo, $projectId, (int) $payment['user_id'], 'Payment Rejected', "Your payment receipt was rejected: {$rejectionReason}");
                log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} rejected {$payment['member_name']}'s payment receipt");
            }

            $pdo->commit();
            json_response(['ok' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/expenses$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $membership = require_project_member($pdo, $user, $projectId);

        $cleanItem = trim((string) ($_POST['itemName'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $numericAmount = (float) ($_POST['amount'] ?? 0);
        $cleanDate = trim((string) ($_POST['purchaseDate'] ?? ''));

        if (strlen($cleanItem) < 2) {
            json_response(['error' => 'Item or component name is required.'], 400);
        }
        if (!is_finite($numericAmount) || $numericAmount <= 0) {
            json_response(['error' => 'Expense amount must be greater than zero.'], 400);
        }
        if ($cleanDate === '') {
            json_response(['error' => 'Purchase date is required.'], 400);
        }

        $uploaded = handle_upload('receipt', 'receipts', true);
        $isLeader = $membership['role'] === 'leader';
        $initialStatus = $isLeader ? 'Approved' : 'Pending';

        $pdo->prepare(
            'INSERT INTO expenses (project_id, item_name, description, amount, purchase_date, purchased_by, receipt_stored_name, receipt_original_name, receipt_mime, status, reviewed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $projectId,
            $cleanItem,
            $description,
            $numericAmount,
            $cleanDate,
            $user['id'],
            $uploaded['stored_name'] ?? null,
            $uploaded['original_name'] ?? null,
            $uploaded['mime_type'] ?? null,
            $initialStatus,
            $isLeader ? $user['id'] : null,
        ]);

        $pStmt = $pdo->prepare('SELECT currency FROM projects WHERE id = ?');
        $pStmt->execute([$projectId]);
        $projCurrency = normalize_currency($pStmt->fetch()['currency'] ?? 'LKR');
        $formattedAmt = format_money($numericAmount, $projCurrency);

        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} logged expense \"{$cleanItem}\" ({$formattedAmt})");
        json_response(['ok' => true], 201);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/expenses/(\d+)/review$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $expenseId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, ['Approved', 'Rejected', 'Archived'], true)) {
            json_response(['error' => 'Invalid expense status.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, item_name FROM expenses WHERE id = ? AND project_id = ?');
        $stmt->execute([$expenseId, $projectId]);
        $expense = $stmt->fetch();
        if (!$expense) {
            json_response(['error' => 'Expense not found.'], 404);
        }

        $pdo->prepare('UPDATE expenses SET status = ?, reviewed_by = ? WHERE id = ? AND project_id = ?')
            ->execute([$status, $user['id'], $expenseId, $projectId]);
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} marked expense \"{$expense['item_name']}\" as {$status}");
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/files/expense/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $expenseId = (int) $m[2];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT receipt_stored_name, receipt_original_name, receipt_mime FROM expenses WHERE id = ? AND project_id = ?');
        $stmt->execute([$expenseId, $projectId]);
        $expense = $stmt->fetch();
        if (!$expense || empty($expense['receipt_stored_name'])) {
            json_response(['error' => 'Expense receipt not found.'], 404);
        }
        $filePath = resolve_safe_upload_path('receipts', (string) $expense['receipt_stored_name']);
        if (!$filePath) {
            json_response(['error' => 'File missing.'], 404);
        }
        stream_protected_file(
            $filePath,
            (string) ($expense['receipt_mime'] ?: 'application/octet-stream'),
            (string) ($expense['receipt_original_name'] ?: 'expense-receipt')
        );
    }

    /* ==================== PROJECT REQUEST BOARD ==================== */

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/requests$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare(
            'SELECT r.*, u.full_name AS requester_name, u.username AS requester_username, u.avatar_color AS requester_color
             FROM requests r
             JOIN users u ON u.id = r.requested_by
             WHERE r.project_id = ?
             ORDER BY r.id DESC'
        );
        $stmt->execute([$projectId]);
        $requests = $stmt->fetchAll();
        foreach ($requests as &$r) {
            $r['id'] = (int) $r['id'];
            $r['project_id'] = (int) $r['project_id'];
            $r['requested_by'] = (int) $r['requested_by'];
        }
        unset($r);
        json_response(['requests' => $requests]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/requests$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $body = read_json_body();
        $cleanTitle = trim((string) ($body['title'] ?? ''));
        if (strlen($cleanTitle) < 3) {
            json_response(['error' => 'Request title is required.'], 400);
        }
        $cleanCategory = trim((string) ($body['category'] ?? 'Hardware Component')) ?: 'Hardware Component';
        $cleanPriority = in_array($body['priority'] ?? '', ['Low', 'Medium', 'High'], true) ? $body['priority'] : 'Medium';

        $pdo->prepare(
            'INSERT INTO requests (project_id, title, description, category, priority, requested_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$projectId, $cleanTitle, trim((string) ($body['description'] ?? '')), $cleanCategory, $cleanPriority, $user['id']]);

        notify_project_members($pdo, $projectId, $user['id'], 'New Project Request', "{$user['full_name']} requested: {$cleanTitle}");
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} submitted request \"{$cleanTitle}\"");
        json_response(['ok' => true], 201);
    }

    if ($method === 'PATCH' && preg_match('#^/projects/(\d+)/requests/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $requestId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);
        require_project_leader($membership);

        $body = read_json_body();
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, ['Pending', 'Approved', 'Rejected', 'In Progress', 'Completed'], true)) {
            json_response(['error' => 'Invalid request status.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, title, requested_by FROM requests WHERE id = ? AND project_id = ?');
        $stmt->execute([$requestId, $projectId]);
        $reqRow = $stmt->fetch();
        if (!$reqRow) {
            json_response(['error' => 'Request not found.'], 404);
        }

        $pdo->prepare('UPDATE requests SET status = ?, updated_at = NOW() WHERE id = ? AND project_id = ?')
            ->execute([$status, $requestId, $projectId]);

        if ((int) $reqRow['requested_by'] !== $user['id']) {
            notify_user($pdo, $projectId, (int) $reqRow['requested_by'], 'Request Updated', "Your request \"{$reqRow['title']}\" is now {$status}.");
        }
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} marked request \"{$reqRow['title']}\" as {$status}");
        json_response(['ok' => true]);
    }

    if ($method === 'DELETE' && preg_match('#^/projects/(\d+)/requests/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $requestId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT requested_by FROM requests WHERE id = ? AND project_id = ?');
        $stmt->execute([$requestId, $projectId]);
        $reqRow = $stmt->fetch();
        if (!$reqRow) {
            json_response(['error' => 'Request not found.'], 404);
        }
        if ($membership['role'] !== 'leader' && (int) $reqRow['requested_by'] !== $user['id']) {
            json_response(['error' => 'You can only delete your own requests.'], 403);
        }

        $pdo->prepare('DELETE FROM requests WHERE id = ? AND project_id = ?')->execute([$requestId, $projectId]);
        json_response(['ok' => true]);
    }

    /* ==================== GROUP CHAT ==================== */

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/messages$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare(
            'SELECT m.*, u.full_name AS sender_name, u.username AS sender_username, u.avatar_color AS sender_color
             FROM messages m
             JOIN users u ON u.id = m.user_id
             WHERE m.project_id = ?
             ORDER BY m.id ASC
             LIMIT 200'
        );
        $stmt->execute([$projectId]);
        $messages = $stmt->fetchAll();
        foreach ($messages as &$msg) {
            $msg['id'] = (int) $msg['id'];
            $msg['project_id'] = (int) $msg['project_id'];
            $msg['user_id'] = (int) $msg['user_id'];
        }
        unset($msg);
        json_response(['messages' => $messages]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/messages$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $bodyText = trim((string) ($_POST['body'] ?? ''));
        $uploaded = handle_upload('attachment', 'attachments', false);
        if ($bodyText === '' && !$uploaded) {
            json_response(['error' => 'Message text or attachment is required.'], 400);
        }

        $pdo->prepare(
            'INSERT INTO messages (project_id, user_id, body, attachment_stored_name, attachment_original_name, attachment_mime)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $projectId,
            $user['id'],
            $bodyText !== '' ? $bodyText : '(Attachment)',
            $uploaded['stored_name'] ?? null,
            $uploaded['original_name'] ?? null,
            $uploaded['mime_type'] ?? null,
        ]);
        json_response(['ok' => true], 201);
    }

    if ($method === 'DELETE' && preg_match('#^/projects/(\d+)/messages/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $messageId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT user_id FROM messages WHERE id = ? AND project_id = ?');
        $stmt->execute([$messageId, $projectId]);
        $msg = $stmt->fetch();
        if (!$msg) {
            json_response(['error' => 'Message not found.'], 404);
        }
        if ((int) $msg['user_id'] !== $user['id'] && $membership['role'] !== 'leader') {
            json_response(['error' => 'You can only delete your own messages.'], 403);
        }

        $pdo->prepare('DELETE FROM messages WHERE id = ? AND project_id = ?')->execute([$messageId, $projectId]);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/files/message/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $messageId = (int) $m[2];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare(
            'SELECT attachment_stored_name, attachment_original_name, attachment_mime FROM messages WHERE id = ? AND project_id = ?'
        );
        $stmt->execute([$messageId, $projectId]);
        $msg = $stmt->fetch();
        if (!$msg || empty($msg['attachment_stored_name'])) {
            json_response(['error' => 'Attachment not found.'], 404);
        }
        $filePath = resolve_safe_upload_path('attachments', (string) $msg['attachment_stored_name']);
        if (!$filePath) {
            json_response(['error' => 'File missing.'], 404);
        }
        stream_protected_file(
            $filePath,
            (string) ($msg['attachment_mime'] ?: 'application/octet-stream'),
            (string) ($msg['attachment_original_name'] ?: 'attachment')
        );
    }

    /* ==================== DOCUMENTS REPOSITORY ==================== */

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/documents$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare(
            'SELECT d.*, u.full_name AS uploader_name
             FROM documents d
             JOIN users u ON u.id = d.uploaded_by
             WHERE d.project_id = ?
             ORDER BY d.id DESC'
        );
        $stmt->execute([$projectId]);
        $docs = $stmt->fetchAll();
        foreach ($docs as &$d) {
            $d['id'] = (int) $d['id'];
            $d['project_id'] = (int) $d['project_id'];
            $d['file_size'] = (int) $d['file_size'];
            $d['uploaded_by'] = (int) $d['uploaded_by'];
        }
        unset($d);
        json_response(['documents' => $docs]);
    }

    if ($method === 'POST' && preg_match('#^/projects/(\d+)/documents$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        require_project_member($pdo, $user, $projectId);

        $uploaded = handle_upload('file', 'documents', false);
        if (!$uploaded) {
            json_response(['error' => 'Please select a document file to upload.'], 400);
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'Documentation')) ?: 'Documentation';
        $docTitle = $title !== '' ? $title : $uploaded['original_name'];

        $pdo->prepare(
            'INSERT INTO documents (project_id, title, category, stored_name, original_name, mime_type, file_size, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $projectId,
            $docTitle,
            $category,
            $uploaded['stored_name'],
            $uploaded['original_name'],
            $uploaded['mime_type'],
            $uploaded['size'],
            $user['id'],
        ]);

        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} uploaded document \"{$docTitle}\"");
        json_response(['ok' => true], 201);
    }

    if ($method === 'GET' && preg_match('#^/projects/(\d+)/files/document/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $docId = (int) $m[2];
        require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT stored_name, original_name, mime_type FROM documents WHERE id = ? AND project_id = ?');
        $stmt->execute([$docId, $projectId]);
        $doc = $stmt->fetch();
        if (!$doc) {
            json_response(['error' => 'Document not found.'], 404);
        }
        $filePath = resolve_safe_upload_path('documents', (string) $doc['stored_name']);
        if (!$filePath) {
            json_response(['error' => 'Stored file missing.'], 404);
        }
        stream_protected_file($filePath, (string) $doc['mime_type'], (string) $doc['original_name']);
    }

    if ($method === 'DELETE' && preg_match('#^/projects/(\d+)/documents/(\d+)$#', $path, $m)) {
        $user = require_auth($pdo);
        $projectId = (int) $m[1];
        $docId = (int) $m[2];
        $membership = require_project_member($pdo, $user, $projectId);

        $stmt = $pdo->prepare('SELECT uploaded_by, title FROM documents WHERE id = ? AND project_id = ?');
        $stmt->execute([$docId, $projectId]);
        $doc = $stmt->fetch();
        if (!$doc) {
            json_response(['error' => 'Document not found.'], 404);
        }
        if ((int) $doc['uploaded_by'] !== $user['id'] && $membership['role'] !== 'leader') {
            json_response(['error' => 'Only the uploader or project leader can delete this document.'], 403);
        }

        $pdo->prepare('DELETE FROM documents WHERE id = ? AND project_id = ?')->execute([$docId, $projectId]);
        log_activity($pdo, $projectId, $user['id'], "{$user['full_name']} removed document \"{$doc['title']}\"");
        json_response(['ok' => true]);
    }

    return false;
}
