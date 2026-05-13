<?php

define("NUCLEUS_SKIP_CORE_BOOTSTRAP", true);
define("NUCLEUS_DB_THROW_ON_FAILURE", true);

try {
    require_once __DIR__ . "/../includes/db.php";
    $pdo = getDbConnection();
    $version = (string) $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "Database connection successful.\n";
    echo "Host: " . DB_HOST . ":" . DB_PORT . "\n";
    echo "Database: " . DB_DATABASE . "\n";
    echo "Server version: " . $version . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}
