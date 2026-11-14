<?php
declare(strict_types=1);

/**
 * BuildFlow XAMPP / MySQL Configuration
 * Default XAMPP credentials: host=127.0.0.1, user=root, password=''
 */
define('BF_DB_HOST', getenv('BF_DB_HOST') ?: '127.0.0.1');
define('BF_DB_PORT', getenv('BF_DB_PORT') ?: '3306');
define('BF_DB_NAME', getenv('BF_DB_NAME') ?: 'buildflow');
define('BF_DB_USER', getenv('BF_DB_USER') ?: 'root');
define('BF_DB_PASS', getenv('BF_DB_PASS') !== false ? (string) getenv('BF_DB_PASS') : '');

define('BF_ROOT_DIR', dirname(__DIR__));
define('BF_UPLOAD_DIR', BF_ROOT_DIR . DIRECTORY_SEPARATOR . 'uploads');
define('BF_SECRET_FILE', BF_ROOT_DIR . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.session_secret');

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        BF_DB_HOST,
        BF_DB_PORT,
        BF_DB_NAME
    );

    $pdo = new PDO($dsn, BF_DB_USER, BF_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);

    return $pdo;
}
