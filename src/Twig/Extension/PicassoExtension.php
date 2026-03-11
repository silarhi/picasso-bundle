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

namespace Silarhi\PicassoBundle\Twig\Extension;

use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PicassoExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImageHelperInterface $imageHelper,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('picasso_image_url', $this->imageHelper->imageUrl(...)),
        ];
    }
}
