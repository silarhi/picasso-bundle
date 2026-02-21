<?php

namespace Silarhi\PicassoBundle\Transformer;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;

interface ImageTransformerInterface
{
    /**
     * Generate a URL for the given image with the specified transformation.
     *
     * @param array<string, mixed> $context Extra context (e.g. loader name for route generation)
     */
    public function url(Image $image, ImageTransformation $transformation, array $context = []): string;
}
