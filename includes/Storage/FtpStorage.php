<?php

require_once __DIR__ . "/StorageInterface.php";
require_once __DIR__ . "/../ftp.php";

class FtpStorage implements StorageInterface
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $rootPath;
    private bool $passiveMode;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->host = (string) ($config["host"] ?? "");
        $this->port = (int) ($config["port"] ?? 21);
        $this->username = (string) ($config["username"] ?? "");
        $this->password = (string) ($config["password"] ?? "");
        $this->rootPath = "/" . trim((string) ($config["root_path"] ?? "/"), "/");
        $this->passiveMode = (bool) ($config["passive_mode"] ?? true);
        $this->timeout = (int) ($config["timeout"] ?? 30);

        if ($this->host === "" || $this->username === "") {
            throw new RuntimeException("FTP storage is not configured.");
        }
    }

    public function put(string $sourceTempPath, string $destinationPath): void
    {
        $remotePath = $this->remotePath($destinationPath);
        uploadFileToFtp($sourceTempPath, $this->relativePathFromRoot($remotePath));
    }

    public function getStream(string $path)
    {
        $remotePath = $this->remotePath($path);
        $tempPath = tempnam(sys_get_temp_dir(), "nucleus_ftp_");
        if ($tempPath === false) {
            throw new RuntimeException("Unable to create temporary download file.");
        }

        try {
            downloadFileFromFtp($this->relativePathFromRoot($remotePath), $tempPath);
            $stream = fopen($tempPath, "rb");
            if (!$stream) {
                throw new RuntimeException("Unable to read temporary download file.");
            }
            register_shutdown_function(static function () use ($tempPath): void {
                if (is_file($tempPath)) {
                    unlink($tempPath);
                }
            });
            return $stream;
        } catch (Throwable $e) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
            throw $e;
        }
    }

    public function exists(string $path): bool
    {
        $connection = $this->connect();
        $size = ftp_size($connection, $this->remotePath($path));
        ftp_close($connection);
        return $size >= 0;
    }

    public function delete(string $path): void
    {
        $connection = $this->connect();
        $remotePath = $this->remotePath($path);
        if (ftp_size($connection, $remotePath) >= 0 && !ftp_delete($connection, $remotePath)) {
            ftp_close($connection);
            throw new RuntimeException("Resource file could not be deleted from FTP storage.");
        }
        ftp_close($connection);
    }

    public function size(string $path): ?int
    {
        $connection = $this->connect();
        $size = ftp_size($connection, $this->remotePath($path));
        ftp_close($connection);
        return $size >= 0 ? (int) $size : null;
    }

    private function connect()
    {
        if (!function_exists("ftp_connect")) {
            throw new RuntimeException("PHP FTP extension is not enabled.");
        }

        $connection = ftp_connect($this->host, $this->port, $this->timeout);
        if (!$connection || !ftp_login($connection, $this->username, $this->password)) {
            throw new RuntimeException("Unable to connect to FTP storage.");
        }

        ftp_pasv($connection, $this->passiveMode);
        return $connection;
    }

    private function remotePath(string $path): string
    {
        return rtrim($this->rootPath, "/") . "/" . $this->normalizePath($path);
    }

    private function relativePathFromRoot(string $path): string
    {
        $root = rtrim($this->rootPath, "/") . "/";
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : ltrim($path, "/");
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace("\\", "/", trim($path));
        $path = ltrim($path, "/");
        if ($path === "" || str_contains($path, "../") || str_contains($path, "..")) {
            throw new InvalidArgumentException("Invalid storage path.");
        }
        return $path;
    }

    private function ensureDirectory($connection, string $directory): void
    {
        $directory = str_replace("\\", "/", $directory);
        $segments = array_values(array_filter(explode("/", trim($directory, "/"))));
        $current = "";
        foreach ($segments as $segment) {
            $current .= "/" . $segment;
            if (@ftp_chdir($connection, $current)) {
                continue;
            }
            if (!@ftp_mkdir($connection, $current) && !@ftp_chdir($connection, $current)) {
                throw new RuntimeException("Unable to create FTP storage directory.");
            }
        }
    }
}
