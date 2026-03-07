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
use Silarhi\PicassoBundle\Placeholder\PlaceholderInterface;

final class StubServicePlaceholder implements PlaceholderInterface
{
    public function generate(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        return 'data:image/png;base64,service-placeholder';
    }
}
