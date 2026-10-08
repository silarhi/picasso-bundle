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

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;

use function sprintf;

/**
 * Reads images from a Flysystem storage.
 *
 * Flysystem failures are reported as a missing file, unless the storage could
 * not be reached or answered with a transient error (see {@see StorageFailure}):
 * those throw ImageSourceUnavailableException, since the file may well be there.
 * Any other error (e.g. a storage client throwing its own exception) is left to
 * propagate unchanged.
 */
final readonly class FlysystemImageSource implements ImageSourceInterface
{
    public function __construct(
        private FilesystemOperator $storage,
    ) {
    }

    public function getStorage(): FilesystemOperator
    {
        return $this->storage;
    }

    public function exists(string $path): bool
    {
        try {
            return $this->storage->fileExists($path);
        } catch (FilesystemException $e) {
            if (StorageFailure::of($e)->transient) {
                throw new ImageSourceUnavailableException(sprintf('Could not check whether image "%s" exists: its storage is unavailable.', $path), 0, $e);
            }

            return false;
        }
    }

    public function readStream(string $path)
    {
        try {
            return $this->storage->readStream($path);
        } catch (FilesystemException $e) {
            if (StorageFailure::of($e)->transient) {
                throw new ImageSourceUnavailableException(sprintf('Image "%s" could not be read: its storage is unavailable.', $path), 0, $e);
            }

            throw new ImageNotFoundException(sprintf('Image "%s" could not be read.', $path), $e->getCode(), previous: $e);
        }
    }
}
