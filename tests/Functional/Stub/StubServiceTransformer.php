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

final class StubServiceTransformer implements ImageTransformerInterface
{
    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $params = [];
        if (null !== $transformation->width) {
            $params['w'] = $transformation->width;
        }
        if (null !== $transformation->format) {
            $params['fm'] = $transformation->format;
        }

        return '/service-transformer/' . $image->path . ([] !== $params ? '?' . http_build_query($params) : '');
    }
}
