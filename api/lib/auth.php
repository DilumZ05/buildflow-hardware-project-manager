<?php
declare(strict_types=1);

function extract_session_token(): ?string
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($authHeader === null && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'authorization') {
                $authHeader = (string) $v;
                break;
            }
        }
    }

    if (is_string($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
        $bearer = trim(substr($authHeader, 7));
        if ($bearer !== '') {
            return $bearer;
        }
    }

    if (!empty($_COOKIE[BF_SESSION_COOKIE]) && is_string($_COOKIE[BF_SESSION_COOKIE])) {
        return $_COOKIE[BF_SESSION_COOKIE];
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['token']) && is_string($_GET['token'])) {
        return $_GET['token'];
    }

    return null;
}

function get_optional_user(PDO $pdo): ?array
{
    $token = extract_session_token();
    if ($token === null) {
        return null;
    }
    $userId = verify_session_token($token);
    if ($userId === null) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, full_name, username, email, avatar_color FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    $user['id'] = (int) $user['id'];
    return $user;
}

function require_auth(PDO $pdo): array
{
    $token = extract_session_token();
    if ($token === null) {
        json_response(['error' => 'Authentication required.'], 401);
    }
    $userId = verify_session_token($token);
    if ($userId === null) {
        json_response(['error' => 'Session expired. Please log in again.'], 401);
    }
    $stmt = $pdo->prepare('SELECT id, full_name, username, email, avatar_color FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        json_response(['error' => 'Account not found.'], 401);
    }
    $user['id'] = (int) $user['id'];
    return $user;
}

function require_project_member(PDO $pdo, array $user, int $projectId): array
{
    if ($projectId <= 0) {
        json_response(['error' => 'Invalid project ID.'], 400);
    }
    $stmt = $pdo->prepare(
        'SELECT project_id, user_id, role, responsibilities FROM project_members WHERE project_id = ? AND user_id = ?'
    );
    $stmt->execute([$projectId, $user['id']]);
    $membership = $stmt->fetch();
    if (!$membership) {
        json_response(['error' => 'Access denied. You are not a member of this project room.'], 403);
    }
    $membership['project_id'] = (int) $membership['project_id'];
    $membership['user_id'] = (int) $membership['user_id'];
    return $membership;
}

function require_project_leader(array $membership): void
{
    if (($membership['role'] ?? '') !== 'leader') {
        json_response(['error' => 'This action is restricted to the project leader.'], 403);
    }
}
