<?php

namespace Silarhi\PicassoBundle\Loader;

use League\Flysystem\FilesystemOperator;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;

class FlysystemLoader implements ServableLoaderInterface
{
    public function __construct(
        private readonly FilesystemOperator $storage,
    ) {
    }

    public function load(ImageReference $reference, bool $withMetadata = true): Image
    {
        return new Image(path: ltrim($reference->path ?? '', '/'));
    }

    public function getSource(): object|string
    {
        return $this->storage;
    }
}
