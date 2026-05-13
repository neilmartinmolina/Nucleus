<?php

require_once __DIR__ . "/StorageInterface.php";

class LocalStorage implements StorageInterface
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        if (!is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
    }

    public function put(string $sourceTempPath, string $destinationPath): void
    {
        $target = $this->resolvePath($destinationPath);
        $directory = dirname($target);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (!is_uploaded_file($sourceTempPath)) {
            if (!copy($sourceTempPath, $target)) {
                throw new RuntimeException("Failed to store local resource.");
            }
            return;
        }

        if (!move_uploaded_file($sourceTempPath, $target)) {
            throw new RuntimeException("Failed to store uploaded resource.");
        }
    }

    public function getStream(string $path)
    {
        $resolved = $this->resolvePath($path);
        if (!is_file($resolved)) {
            throw new RuntimeException("Resource file was not found.");
        }

        $stream = fopen($resolved, "rb");
        if (!$stream) {
            throw new RuntimeException("Resource file could not be opened.");
        }
        return $stream;
    }

    public function exists(string $path): bool
    {
        return is_file($this->resolvePath($path));
    }

    public function delete(string $path): void
    {
        $resolved = $this->resolvePath($path);
        if (is_file($resolved) && !unlink($resolved)) {
            throw new RuntimeException("Resource file could not be deleted.");
        }
    }

    public function size(string $path): ?int
    {
        $resolved = $this->resolvePath($path);
        return is_file($resolved) ? (int) filesize($resolved) : null;
    }

    private function resolvePath(string $path): string
    {
        $normalized = $this->normalizePath($path);
        return $this->root . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $normalized);
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
}
