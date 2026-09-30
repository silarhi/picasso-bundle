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

namespace Silarhi\PicassoBundle\Source;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Silarhi\PicassoBundle\Exception\PicassoExceptionInterface;

/**
 * Read-only Flysystem adapter over an {@see ImageSourceInterface}.
 *
 * Bridges image sources to libraries that read through Flysystem (e.g. Glide).
 * Bundle exceptions are translated to Flysystem ones so those libraries keep
 * their own error handling. Writes and metadata lookups are refused, and the
 * source exposes no directories: an image source is a flat path lookup.
 *
 * Only exception factories available in Flysystem 2 are used, as both 2.x and
 * 3.x are supported.
 */
final readonly class ImageSourceFlysystemAdapter implements FilesystemAdapter
{
    private const READ_ONLY = 'Image sources are read-only.';

    public function __construct(
        private ImageSourceInterface $source,
    ) {
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->source->exists($path);
        } catch (PicassoExceptionInterface $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, self::READ_ONLY);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, self::READ_ONLY);
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if (false === $contents) {
            throw UnableToReadFile::fromLocation($path, 'The stream could not be read.');
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        try {
            return $this->source->readStream($path);
        } catch (PicassoExceptionInterface $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        throw UnableToDeleteFile::atLocation($path, self::READ_ONLY);
    }

    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, self::READ_ONLY);
    }

    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, self::READ_ONLY);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, self::READ_ONLY);
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'Not supported by image sources.');
    }

    public function mimeType(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::mimeType($path, 'Not supported by image sources.');
    }

    public function lastModified(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::lastModified($path, 'Not supported by image sources.');
    }

    public function fileSize(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::fileSize($path, 'Not supported by image sources.');
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }
}
