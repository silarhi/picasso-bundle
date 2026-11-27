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

namespace Silarhi\PicassoBundle\Tests\Functional\Stub;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

/**
 * An image CDN taking the image and its transformation in the query string: its URLs have no path.
 */
final class StubQueryTransformer implements ImageTransformerInterface
{
    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        return 'https://cdn.example.com?' . http_build_query(['src' => $image->path, 'w' => $transformation->width, 'fm' => $transformation->format]);
    }
}
