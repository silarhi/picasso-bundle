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
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;

final readonly class FlysystemLoader implements ServableLoaderInterface
{
    public function __construct(
        private FilesystemOperator $storage,
    ) {
    }

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $path = ltrim($reference->path ?? '', '/');
        if ('' === $path) {
            return new Image();
        }

        return new Image(path: $path, stream: fn () => $this->storage->readStream($path));
    }

    /** @param array<string, mixed> $metadata */
    public function getSource(array $metadata): FilesystemOperator
    {
        return $this->storage;
    }
}
