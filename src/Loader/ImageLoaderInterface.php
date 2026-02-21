<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;

interface ImageLoaderInterface
{
    /**
     * Load an image from a reference.
     *
     * @param bool $withMetadata When true, detect width/height/mimeType (may be expensive).
     *                           When false, skip detection (use when client provides dimensions).
     */
    public function load(ImageReference $reference, bool $withMetadata = true): Image;
}
