<?php
require_once __DIR__ . "/../config.php";

function getDbConnection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    if (DB_CONNECTION !== "mysql") {
        throw new RuntimeException("Unsupported database connection: " . DB_CONNECTION);
    }

    $dsn = sprintf(
        "mysql:host=%s;port=%d;dbname=%s;charset=%s",
        DB_HOST,
        DB_PORT,
        DB_DATABASE,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $connection = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
    return $connection;
}

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    if (defined("NUCLEUS_DB_THROW_ON_FAILURE") && NUCLEUS_DB_THROW_ON_FAILURE) {
        throw new RuntimeException($e->getMessage());
    }
    error_log("DB connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}
