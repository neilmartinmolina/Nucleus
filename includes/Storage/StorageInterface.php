<?php

interface StorageInterface
{
    public function put(string $sourceTempPath, string $destinationPath): void;

    /**
     * @return resource
     */
    public function getStream(string $path);

    public function exists(string $path): bool;

    public function delete(string $path): void;

    public function size(string $path): ?int;
}
