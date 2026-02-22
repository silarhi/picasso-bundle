<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;

interface ImageLoaderInterface
{
    public function load(ImageReference $reference, bool $withMetadata = false): Image;
}
