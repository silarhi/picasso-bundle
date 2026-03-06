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
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Throwable;

class FlysystemLoader implements ServableLoaderInterface
{
    public function __construct(
        private readonly FilesystemOperator $storage,
        private readonly MetadataGuesserInterface $metadataGuesser,
    ) {
    }

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $path = ltrim($reference->path ?? '', '/');
        $width = null;
        $height = null;
        $mimeType = null;
        $stream = null;

        if ($withMetadata && '' !== $path) {
            try {
                $stream = $this->storage->readStream($path);
                $guessed = $this->metadataGuesser->guess($stream);
                $width = $guessed['width'];
                $height = $guessed['height'];
                $mimeType = $guessed['mimeType'];
            } catch (Throwable) {
                // Stream not available
            }
        }

        return new Image(path: $path, stream: $stream, width: $width, height: $height, mimeType: $mimeType);
    }

    public function getSource(): FilesystemOperator
    {
        return $this->storage;
    }
}
