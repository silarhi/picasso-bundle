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

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Source\LocalImageSource;

/**
 * Reads images from one local directory.
 */
final readonly class FilesystemLoader implements ServableLoaderInterface
{
    private LocalImageSource $source;

    public function __construct(string $path)
    {
        $this->source = new LocalImageSource($path);
    }

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $path = ltrim($reference->path ?? '', '/');

        if (!$this->source->exists($path)) {
            return new Image(path: $path);
        }

        return new Image(path: $path, stream: fn () => $this->source->readStream($path));
    }

    public function getSource(): LocalImageSource
    {
        return $this->source;
    }
}
