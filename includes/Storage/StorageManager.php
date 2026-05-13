<?php

require_once __DIR__ . "/StorageInterface.php";
require_once __DIR__ . "/LocalStorage.php";
require_once __DIR__ . "/FtpStorage.php";

class StorageManager
{
    public static function defaultDriver(): string
    {
        $driver = strtolower((string) STORAGE_DEFAULT_DRIVER);
        return in_array($driver, ["local", "ftp"], true) ? $driver : "local";
    }

    public static function driver(?string $driver = null): StorageInterface
    {
        $driver = strtolower((string) ($driver ?: self::defaultDriver()));
        if ($driver === "ftp") {
            return new FtpStorage([
                "host" => FTP_STORAGE_HOST,
                "port" => FTP_STORAGE_PORT,
                "username" => FTP_STORAGE_USERNAME,
                "password" => FTP_STORAGE_PASSWORD,
                "root_path" => FTP_STORAGE_ROOT_PATH,
                "passive_mode" => FTP_STORAGE_PASSIVE_MODE,
                "timeout" => FTP_STORAGE_TIMEOUT,
            ]);
        }

        if ($driver === "local") {
            return new LocalStorage(STORAGE_LOCAL_ROOT);
        }

        throw new InvalidArgumentException("Unsupported storage driver.");
    }

    public static function safeOriginalFilename(string $filename): string
    {
        $filename = basename(str_replace("\\", "/", $filename));
        $filename = preg_replace('/[^A-Za-z0-9._ -]/', "_", $filename) ?: "resource";
        $filename = trim(preg_replace('/\s+/', " ", $filename));
        return $filename !== "" ? substr($filename, 0, 180) : "resource";
    }

    public static function safeStoredFilename(string $originalFilename): string
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $base = pathinfo($originalFilename, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]/', "_", $base) ?: "resource";
        $base = substr($base, 0, 80);
        $suffix = bin2hex(random_bytes(12));
        return $extension ? "{$base}_{$suffix}.{$extension}" : "{$base}_{$suffix}";
    }

    public static function destinationPath(int $projectId, string $storedFilename): string
    {
        return "projects/" . $projectId . "/" . date("Y/m") . "/" . $storedFilename;
    }

    public static function isBlockedExtension(string $filename): bool
    {
        $blocked = ["php", "phtml", "phar", "exe", "bat", "cmd", "sh", "js", "jsp", "asp", "aspx", "cgi", "pl"];
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $blocked, true);
    }

    public static function detectMimeType(string $path): string
    {
        if (function_exists("finfo_open")) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== "") {
                    return $mime;
                }
            }
        }

        return "application/octet-stream";
    }
}
