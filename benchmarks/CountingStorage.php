<?php

declare(strict_types=1);

/*
 * This file is part of the Picasso Bundle package.
 *
 * (c) SILARHI <dev@silarhi.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Silarhi\PicassoBundle\Benchmarks;

use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * A local storage that counts its calls and can wait before each one, to mimic
 * an object store where every call is an HTTP request. Writes are appended to a
 * log shared by every process, so concurrent renders of one variant show up.
 */
final class CountingStorage implements FilesystemOperator
{
    /** @var array<string, int> */
    public static array $calls = [];

    private readonly FilesystemOperator $inner;

    public function __construct(string $root, private readonly int $latencyUs = 0, private readonly ?string $writeLog = null)
    {
        $this->inner = new Filesystem(new LocalFilesystemAdapter($root));
    }

    private function call(string $method, string $path = ''): void
    {
        self::$calls[$method] = (self::$calls[$method] ?? 0) + 1;
        if ($this->latencyUs > 0) {
            usleep($this->latencyUs);
        }
        if (null !== $this->writeLog && str_starts_with($method, 'write')) {
            file_put_contents($this->writeLog, getmypid() . ' ' . $path . "\n", \FILE_APPEND | \LOCK_EX);
        }
    }

    public function fileExists(string $location): bool
    {
        $this->call(__FUNCTION__);

        return $this->inner->fileExists($location);
    }

    public function directoryExists(string $location): bool
    {
        $this->call(__FUNCTION__);

        return $this->inner->directoryExists($location);
    }

    public function has(string $location): bool
    {
        $this->call(__FUNCTION__);

        return $this->inner->has($location);
    }

    public function read(string $location): string
    {
        $this->call(__FUNCTION__);

        return $this->inner->read($location);
    }

    public function readStream(string $location)
    {
        $this->call(__FUNCTION__);

        return $this->inner->readStream($location);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        $this->call(__FUNCTION__);

        return $this->inner->listContents($location, $deep);
    }

    public function lastModified(string $path): int
    {
        $this->call(__FUNCTION__);

        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): int
    {
        $this->call(__FUNCTION__);

        return $this->inner->fileSize($path);
    }

    public function mimeType(string $path): string
    {
        $this->call(__FUNCTION__);

        return $this->inner->mimeType($path);
    }

    public function visibility(string $path): string
    {
        $this->call(__FUNCTION__);

        return $this->inner->visibility($path);
    }

    /**
     * @param array<mixed> $config
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->call(__FUNCTION__, $location);
        $this->inner->write($location, $contents, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->call(__FUNCTION__, $location);
        $this->inner->writeStream($location, $contents, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->call(__FUNCTION__);
        $this->inner->setVisibility($path, $visibility);
    }

    public function delete(string $location): void
    {
        $this->call(__FUNCTION__);
        $this->inner->delete($location);
    }

    public function deleteDirectory(string $location): void
    {
        $this->call(__FUNCTION__);
        $this->inner->deleteDirectory($location);
    }

    /**
     * @param array<mixed> $config
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->call(__FUNCTION__);
        $this->inner->createDirectory($location, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->call(__FUNCTION__);
        $this->inner->move($source, $destination, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->call(__FUNCTION__);
        $this->inner->copy($source, $destination, $config);
    }
}
