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

namespace Silarhi\PicassoBundle\Placeholder;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;

/**
 * @phpstan-import-type TransformerContext from \Silarhi\PicassoBundle\Transformer\ImageTransformerInterface
 */
interface PlaceholderInterface
{
    /**
     * Generate a placeholder data URI or URL for the given image.
     *
     * @param TransformerContext $context Extra context (loader name, transformer name, etc.)
     */
    public function generate(Image $image, ImageTransformation $transformation, array $context = []): string;
}
