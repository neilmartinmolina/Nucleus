<?php

if (!function_exists("nucleusEnv")) {
    function nucleusEnv(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return $value;
    }
}

if (!function_exists("nucleusEnvBool")) {
    function nucleusEnvBool(string $key, bool $default = false): bool
    {
        return filter_var(nucleusEnv($key, $default ? "true" : "false"), FILTER_VALIDATE_BOOLEAN);
    }
}

if (!function_exists("nucleusEnvInt")) {
    function nucleusEnvInt(string $key, int $default = 0): int
    {
        $value = nucleusEnv($key, null);
        return is_numeric($value) ? (int) $value : $default;
    }
}

return [
    "app" => [
        "env" => (string) nucleusEnv("APP_ENV", "development"),
        "debug" => nucleusEnvBool("APP_DEBUG", true),
        "url" => (string) nucleusEnv("APP_URL", "http://localhost"),
    ],
    "database" => [
        "connection" => (string) nucleusEnv("DB_CONNECTION", "mysql"),
        "host" => (string) nucleusEnv("DB_HOST", nucleusEnv("DB_HOST_LEGACY", "localhost")),
        "port" => nucleusEnvInt("DB_PORT", 3306),
        "database" => (string) nucleusEnv("DB_DATABASE", nucleusEnv("DB_NAME", "nucleus")),
        "username" => (string) nucleusEnv("DB_USERNAME", nucleusEnv("DB_USER", "root")),
        "password" => (string) nucleusEnv("DB_PASSWORD", nucleusEnv("DB_PASS", "")),
        "charset" => (string) nucleusEnv("DB_CHARSET", "utf8mb4"),
    ],
    "files" => [
        "driver" => strtolower((string) nucleusEnv("FILE_STORAGE_DRIVER", nucleusEnv("STORAGE_DEFAULT_DRIVER", "local"))),
        "upload_max_bytes" => nucleusEnvInt("UPLOAD_MAX_BYTES", nucleusEnvInt("RESOURCE_MAX_FILE_SIZE", 25 * 1024 * 1024)),
        "admin_quota_bytes" => nucleusEnvInt("ADMIN_QUOTA_BYTES", nucleusEnvInt("RESOURCE_PROJECT_QUOTA_BYTES", 250 * 1024 * 1024)),
        "handler_quota_bytes" => nucleusEnvInt("HANDLER_QUOTA_BYTES", 1024 * 1024 * 1024),
        "local_root" => (string) nucleusEnv("STORAGE_LOCAL_ROOT", dirname(__DIR__) . "/storage/resources"),
    ],
    "ftp" => [
        "host" => (string) nucleusEnv("FTP_HOST", nucleusEnv("FTP_STORAGE_HOST", "")),
        "port" => nucleusEnvInt("FTP_PORT", nucleusEnvInt("FTP_STORAGE_PORT", 21)),
        "username" => (string) nucleusEnv("FTP_USERNAME", nucleusEnv("FTP_STORAGE_USERNAME", "")),
        "password" => (string) nucleusEnv("FTP_PASSWORD", nucleusEnv("FTP_STORAGE_PASSWORD", "")),
        "root" => (string) nucleusEnv("FTP_ROOT", nucleusEnv("FTP_STORAGE_ROOT_PATH", "/storage")),
        "passive" => nucleusEnvBool("FTP_PASSIVE", nucleusEnvBool("FTP_STORAGE_PASSIVE_MODE", true)),
        "timeout" => nucleusEnvInt("FTP_TIMEOUT", 30),
    ],
];
