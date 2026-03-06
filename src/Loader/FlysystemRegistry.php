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

namespace Silarhi\PicassoBundle\Loader;

use League\Flysystem\FilesystemOperator;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Exception\InvalidMetadataException;

use function sprintf;

final readonly class FlysystemRegistry
{
    public function __construct(
        private ContainerInterface $storages,
    ) {
    }

    public function get(string $storageName): FilesystemOperator
    {
        if (!$this->storages->has($storageName)) {
            throw new InvalidMetadataException(sprintf('Flysystem storage "%s" is not registered. Available storages are collected from services tagged with "flysystem.storage".', $storageName));
        }

        /** @var FilesystemOperator $storage */
        $storage = $this->storages->get($storageName);

        return $storage;
    }

    public function has(string $storageName): bool
    {
        return $this->storages->has($storageName);
    }
}
