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
