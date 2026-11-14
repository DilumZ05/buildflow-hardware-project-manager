<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/uploads.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/projects.php';
require_once __DIR__ . '/routes/workspace.php';
require_once __DIR__ . '/routes/budget_collab.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$apiPath = preg_replace('#^.*?/api(?=/|$)#', '', $requestPath) ?? '/';
$apiPath = preg_replace('#^/index\.php#', '', $apiPath) ?? '/';
$apiPath = '/' . ltrim($apiPath, '/');
if ($apiPath !== '/') {
    $apiPath = rtrim($apiPath, '/');
}

try {
    $pdo = get_db();
} catch (Throwable $e) {
    json_response([
        'error' => 'Database connection failed. Start MySQL in XAMPP Control Panel and import database/buildflow_mysql.sql in phpMyAdmin.',
    ], 500);
}

try {
    if (handle_auth_route($pdo, $method, $apiPath)) {
        exit;
    }
    if (handle_project_routes($pdo, $method, $apiPath)) {
        exit;
    }
    if (handle_workspace_routes($pdo, $method, $apiPath)) {
        exit;
    }
    if (handle_budget_and_collab_routes($pdo, $method, $apiPath)) {
        exit;
    }
    json_response(['error' => 'API endpoint not found.'], 404);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage() ?: 'Server error.'], 500);
}
