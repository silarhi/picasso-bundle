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

namespace Silarhi\PicassoBundle\Tests\Transformer\Stub;

use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * A local storage counting its calls, each an HTTP request on an object store.
 * As a remote one, its streams are in-memory copies, which state neither a size
 * nor a date, like the HTTP body streams of object store adapters.
 */
final class CountingFilesystem implements FilesystemOperator
{
    /** @var array<string, int> */
    public array $calls = [];

    private readonly FilesystemOperator $inner;

    public function __construct(string $root, private readonly bool $remote = false)
    {
        $this->inner = new Filesystem(new LocalFilesystemAdapter($root));
    }

    public function total(): int
    {
        return array_sum($this->calls);
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    private function count(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }

    public function fileExists(string $location): bool
    {
        $this->count(__FUNCTION__);

        return $this->inner->fileExists($location);
    }

    public function directoryExists(string $location): bool
    {
        $this->count(__FUNCTION__);

        return $this->inner->directoryExists($location);
    }

    public function has(string $location): bool
    {
        $this->count(__FUNCTION__);

        return $this->inner->has($location);
    }

    public function read(string $location): string
    {
        $this->count(__FUNCTION__);

        return $this->inner->read($location);
    }

    public function readStream(string $location)
    {
        $this->count(__FUNCTION__);
        $stream = $this->inner->readStream($location);
        if (!$this->remote) {
            return $stream;
        }

        $copy = fopen('php://memory', 'w+');
        if (false === $copy) {
            throw new RuntimeException('Cannot open a memory stream.');
        }
        stream_copy_to_stream($stream, $copy);
        fclose($stream);
        rewind($copy);

        return $copy;
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        $this->count(__FUNCTION__);

        return $this->inner->listContents($location, $deep);
    }

    public function lastModified(string $path): int
    {
        $this->count(__FUNCTION__);

        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): int
    {
        $this->count(__FUNCTION__);

        return $this->inner->fileSize($path);
    }

    public function mimeType(string $path): string
    {
        $this->count(__FUNCTION__);

        return $this->inner->mimeType($path);
    }

    public function visibility(string $path): string
    {
        $this->count(__FUNCTION__);

        return $this->inner->visibility($path);
    }

    /**
     * @param array<mixed> $config
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->count(__FUNCTION__);
        $this->inner->write($location, $contents, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->count(__FUNCTION__);
        $this->inner->writeStream($location, $contents, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->count(__FUNCTION__);
        $this->inner->setVisibility($path, $visibility);
    }

    public function delete(string $location): void
    {
        $this->count(__FUNCTION__);
        $this->inner->delete($location);
    }

    public function deleteDirectory(string $location): void
    {
        $this->count(__FUNCTION__);
        $this->inner->deleteDirectory($location);
    }

    /**
     * @param array<mixed> $config
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->count(__FUNCTION__);
        $this->inner->createDirectory($location, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->count(__FUNCTION__);
        $this->inner->move($source, $destination, $config);
    }

    /**
     * @param array<mixed> $config
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->count(__FUNCTION__);
        $this->inner->copy($source, $destination, $config);
    }
}
