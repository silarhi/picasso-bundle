<?php

declare(strict_types=1);

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

    public function load(ImageReference $reference): Image
    {
        return new Image(path: ltrim($reference->path ?? '', '/'));
    }

    public function getSource(): FilesystemOperator
    {
        return $this->storage;
    }
}
