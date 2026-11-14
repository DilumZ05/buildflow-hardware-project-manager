<?php
declare(strict_types=1);

const BF_SESSION_COOKIE = 'bf_session';

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function get_session_secret(): string
{
    $env = getenv('SESSION_SECRET');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    if (is_file(BF_SECRET_FILE)) {
        $val = trim((string) file_get_contents(BF_SECRET_FILE));
        if ($val !== '') {
            return $val;
        }
    }
    $generated = bin2hex(random_bytes(32));
    $dir = dirname(BF_SECRET_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (@file_put_contents(BF_SECRET_FILE, $generated) !== false) {
        return $generated;
    }
    return hash('sha256', __FILE__ . PHP_OS . BF_DB_NAME);
}

function hash_app_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function verify_app_password(string $password, string $storedHash): bool
{
    if (str_starts_with($storedHash, 'sha256:')) {
        $parts = explode(':', $storedHash, 3);
        if (count($parts) !== 3) {
            return false;
        }
        [, $salt, $expected] = $parts;
        $computed = hash('sha256', $salt . $password);
        return hash_equals($expected, $computed);
    }
    return password_verify($password, $storedHash);
}

function create_session_token(int $userId): string
{
    $issuedAtMs = (int) round(microtime(true) * 1000);
    $nonce = bin2hex(random_bytes(8));
    $payload = "{$userId}.{$issuedAtMs}.{$nonce}";
    $sig = hash_hmac('sha256', $payload, get_session_secret());
    return "{$payload}.{$sig}";
}

function verify_session_token(string $token): ?int
{
    $parts = explode('.', $token);
    if (count($parts) !== 4) {
        return null;
    }
    [$userIdStr, $issuedAtStr, $nonce, $sig] = $parts;
    $payload = "{$userIdStr}.{$issuedAtStr}.{$nonce}";
    $expectedSig = hash_hmac('sha256', $payload, get_session_secret());
    if (!hash_equals($expectedSig, $sig)) {
        return null;
    }
    if (!ctype_digit($userIdStr) || !is_numeric($issuedAtStr)) {
        return null;
    }
    $userId = (int) $userIdStr;
    $issuedAtMs = (float) $issuedAtStr;
    if ($userId <= 0) {
        return null;
    }
    $nowMs = microtime(true) * 1000;
    if (($nowMs - $issuedAtMs) > (7 * 24 * 60 * 60 * 1000)) {
        return null;
    }
    return $userId;
}

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return true;
    }
    return false;
}

function set_session_cookie(int $userId): string
{
    $token = create_session_token($userId);
    $isHttps = is_https_request();
    setcookie(BF_SESSION_COOKIE, $token, [
        'expires' => time() + (7 * 24 * 60 * 60),
        'path' => '/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => $isHttps ? 'None' : 'Lax',
    ]);
    return $token;
}

function clear_session_cookie(): void
{
    $isHttps = is_https_request();
    setcookie(BF_SESSION_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => $isHttps ? 'None' : 'Lax',
    ]);
}

function round_currency(float $value): float
{
    return round($value, 2);
}

function normalize_currency(mixed $value): string
{
    return strtoupper(trim((string) ($value ?? ''))) === 'USD' ? 'USD' : 'LKR';
}

function format_money(float $amount, string $currency = 'LKR'): string
{
    $abs = abs($amount);
    $formatted = number_format($abs, 2, '.', ',');
    $prefix = $currency === 'USD' ? '$' : 'Rs. ';
    return $amount < 0 ? "-{$prefix}{$formatted}" : "{$prefix}{$formatted}";
}

function calculate_completion_percentage(array $tasks): int
{
    $total = count($tasks);
    if ($total === 0) {
        return 0;
    }
    $completed = 0;
    foreach ($tasks as $t) {
        if (($t['status'] ?? '') === 'Completed') {
            $completed++;
        }
    }
    return (int) round(($completed / $total) * 100);
}

function calculate_project_finances(float $plannedBudget, array $contributions, array $payments, array $expenses): array
{
    $activeContributionIds = [];
    foreach ($contributions as $c) {
        if (($c['status'] ?? '') === 'Active') {
            $activeContributionIds[(int) $c['id']] = true;
        }
    }

    $totalRequested = 0.0;
    $totalVerifiedContributions = 0.0;
    $pendingVerificationAmount = 0.0;
    $pendingSubmissionCount = 0;

    foreach ($payments as $p) {
        $cid = (int) $p['contribution_id'];
        if (isset($activeContributionIds[$cid])) {
            $totalRequested += (float) ($p['expected_amount'] ?? 0);
        }
        $status = (string) ($p['status'] ?? '');
        if ($status === 'Verified') {
            $totalVerifiedContributions += (float) ($p['submitted_amount'] ?? $p['expected_amount'] ?? 0);
        } elseif ($status === 'Submitted') {
            $pendingVerificationAmount += (float) ($p['submitted_amount'] ?? $p['expected_amount'] ?? 0);
            $pendingSubmissionCount++;
        } elseif ($status === 'Not Submitted' || $status === 'Rejected') {
            $pendingSubmissionCount++;
        }
    }

    $totalApprovedExpenses = 0.0;
    $pendingExpensesAmount = 0.0;
    foreach ($expenses as $e) {
        $status = (string) ($e['status'] ?? '');
        if ($status === 'Approved') {
            $totalApprovedExpenses += (float) ($e['amount'] ?? 0);
        } elseif ($status === 'Pending') {
            $pendingExpensesAmount += (float) ($e['amount'] ?? 0);
        }
    }

    $availableBalance = round_currency($totalVerifiedContributions - $totalApprovedExpenses);

    return [
        'plannedBudget' => round_currency($plannedBudget),
        'totalRequested' => round_currency($totalRequested),
        'totalVerifiedContributions' => round_currency($totalVerifiedContributions),
        'pendingVerificationAmount' => round_currency($pendingVerificationAmount),
        'pendingSubmissionCount' => $pendingSubmissionCount,
        'totalApprovedExpenses' => round_currency($totalApprovedExpenses),
        'pendingExpensesAmount' => round_currency($pendingExpensesAmount),
        'availableBalance' => $availableBalance,
    ];
}

function evaluate_component_stock(int $qtyRequired, int $qtyAvailable, string $purchaseStatus): array
{
    $req = max(0, $qtyRequired);
    $avail = max(0, $qtyAvailable);
    $shortfall = max(0, $req - $avail);

    if ($shortfall === 0 && $req > 0) {
        return ['shortfall' => 0, 'effectiveStatus' => 'Available'];
    }
    if ($avail === 0) {
        return [
            'shortfall' => $shortfall,
            'effectiveStatus' => $purchaseStatus === 'Ordered' ? 'Ordered' : 'Not Purchased',
        ];
    }
    return [
        'shortfall' => $shortfall,
        'effectiveStatus' => $purchaseStatus === 'Ordered' ? 'Ordered' : 'Needed',
    ];
}

function can_verify_payment(
    int $actorId,
    string $actorRole,
    int $paymentUserId,
    string $paymentUserRole,
    string $currentStatus
): array {
    if ($actorId === $paymentUserId) {
        return ['allowed' => false, 'reason' => 'Users cannot verify their own payment submissions.'];
    }
    $isLeaderPayment = $paymentUserRole === 'leader';
    if ($actorRole !== 'leader' && !$isLeaderPayment) {
        return ['allowed' => false, 'reason' => 'Only the project leader can verify or reject member payments.'];
    }
    if ($currentStatus === 'Verified') {
        return ['allowed' => false, 'reason' => 'This payment has already been verified.'];
    }
    if ($currentStatus !== 'Submitted') {
        return ['allowed' => false, 'reason' => 'Only submitted receipts can be verified or rejected.'];
    }
    return ['allowed' => true];
}

function generate_room_code(PDO $pdo): string
{
    $rows = $pdo->query('SELECT room_code FROM projects')->fetchAll();
    $existing = [];
    foreach ($rows as $r) {
        $existing[(string) $r['room_code']] = true;
    }

    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len = strlen($alphabet);
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= $alphabet[random_int(0, $len - 1)];
        }
        $candidate = 'BF-' . $suffix;
        if (!isset($existing[$candidate])) {
            return $candidate;
        }
    }
    return 'BF-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function log_activity(PDO $pdo, int $projectId, ?int $userId, string $actionText): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_logs (project_id, user_id, action_text) VALUES (?, ?, ?)');
    $stmt->execute([$projectId, $userId, $actionText]);
}

function notify_user(PDO $pdo, int $projectId, int $userId, string $title, string $message): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications (project_id, user_id, title, message) VALUES (?, ?, ?, ?)');
    $stmt->execute([$projectId, $userId, $title, $message]);
}

function notify_project_members(PDO $pdo, int $projectId, ?int $excludeUserId, string $title, string $message): void
{
    $stmt = $pdo->prepare('SELECT user_id FROM project_members WHERE project_id = ?');
    $stmt->execute([$projectId]);
    $members = $stmt->fetchAll();

    $ins = $pdo->prepare('INSERT INTO notifications (project_id, user_id, title, message) VALUES (?, ?, ?, ?)');
    foreach ($members as $m) {
        $uid = (int) $m['user_id'];
        if ($excludeUserId !== null && $uid === $excludeUserId) {
            continue;
        }
        $ins->execute([$projectId, $uid, $title, $message]);
    }
}
