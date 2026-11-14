<?php
declare(strict_types=1);

function handle_auth_route(PDO $pdo, string $method, string $path): bool
{
    $avatarColors = ['#2563EB', '#7C3AED', '#16A34A', '#D97706', '#0284C7', '#DC2626'];

    if ($method === 'POST' && $path === '/auth/register') {
        $body = read_json_body();
        $cleanName = trim((string) ($body['fullName'] ?? ''));
        $cleanUser = strtolower(trim((string) ($body['username'] ?? '')));
        $cleanEmail = strtolower(trim((string) ($body['email'] ?? '')));
        $pass = (string) ($body['password'] ?? '');
        $confirm = (string) ($body['confirmPassword'] ?? '');

        if (strlen($cleanName) < 2) {
            json_response(['error' => 'Full name must be at least 2 characters.'], 400);
        }
        if (!preg_match('/^[a-z0-9_]{3,24}$/', $cleanUser)) {
            json_response(['error' => 'Username must be 3–24 characters (letters, numbers, underscores).'], 400);
        }
        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(['error' => 'Please enter a valid email address.'], 400);
        }
        if (strlen($pass) < 6) {
            json_response(['error' => 'Password must be at least 6 characters.'], 400);
        }
        if ($pass !== $confirm) {
            json_response(['error' => 'Password and confirmation do not match.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$cleanUser, $cleanEmail]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ($existing['username'] === $cleanUser) {
                json_response(['error' => 'This username is already taken.'], 409);
            }
            json_response(['error' => 'An account with this email address already exists.'], 409);
        }

        $color = $avatarColors[random_int(0, count($avatarColors) - 1)];
        $passHash = hash_app_password($pass);

        $ins = $pdo->prepare(
            'INSERT INTO users (full_name, username, email, password_hash, avatar_color) VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$cleanName, $cleanUser, $cleanEmail, $passHash, $color]);
        $userId = (int) $pdo->lastInsertId();
        $token = set_session_cookie($userId);

        $uStmt = $pdo->prepare('SELECT id, full_name, username, email, avatar_color FROM users WHERE id = ?');
        $uStmt->execute([$userId]);
        $user = $uStmt->fetch();
        $user['id'] = (int) $user['id'];

        json_response(['user' => $user, 'token' => $token], 201);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        $body = read_json_body();
        $cleanId = strtolower(trim((string) ($body['identifier'] ?? '')));
        $pass = (string) ($body['password'] ?? '');

        if ($cleanId === '' || $pass === '') {
            json_response(['error' => 'Username or email and password are required.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT id, full_name, username, email, password_hash, avatar_color FROM users WHERE LOWER(username) = ? OR LOWER(email) = ? LIMIT 1'
        );
        $stmt->execute([$cleanId, $cleanId]);
        $user = $stmt->fetch();

        if (!$user || !verify_app_password($pass, (string) $user['password_hash'])) {
            json_response(['error' => 'Invalid username/email or password.'], 401);
        }

        $userId = (int) $user['id'];
        $token = set_session_cookie($userId);

        json_response([
            'token' => $token,
            'user' => [
                'id' => $userId,
                'full_name' => $user['full_name'],
                'username' => $user['username'],
                'email' => $user['email'],
                'avatar_color' => $user['avatar_color'],
            ],
        ]);
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        clear_session_cookie();
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/auth/me') {
        $user = get_optional_user($pdo);
        json_response(['user' => $user]);
    }

    if ($method === 'PATCH' && $path === '/auth/profile') {
        $user = require_auth($pdo);
        $body = read_json_body();
        $cleanName = trim((string) ($body['fullName'] ?? ''));
        $cleanEmail = strtolower(trim((string) ($body['email'] ?? '')));

        if (strlen($cleanName) < 2) {
            json_response(['error' => 'Full name must be at least 2 characters.'], 400);
        }
        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(['error' => 'Valid email is required.'], 400);
        }

        $dup = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id != ? LIMIT 1');
        $dup->execute([$cleanEmail, $user['id']]);
        if ($dup->fetch()) {
            json_response(['error' => 'Email is already in use by another account.'], 409);
        }

        $upd = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
        $upd->execute([$cleanName, $cleanEmail, $user['id']]);

        $uStmt = $pdo->prepare('SELECT id, full_name, username, email, avatar_color FROM users WHERE id = ?');
        $uStmt->execute([$user['id']]);
        $updated = $uStmt->fetch();
        $updated['id'] = (int) $updated['id'];

        json_response(['user' => $updated]);
    }

    return false;
}
