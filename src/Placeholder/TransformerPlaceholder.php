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

use function is_string;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Service\TransformerRegistry;

/**
 * @phpstan-import-type TransformerContext from \Silarhi\PicassoBundle\Transformer\ImageTransformerInterface
 */
final readonly class TransformerPlaceholder implements PlaceholderInterface
{
    public function __construct(
        private TransformerRegistry $transformerRegistry,
        private int $size,
        private int $blur,
        private int $quality,
    ) {
    }

    public function generate(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        if (!is_string($context['transformer'] ?? null)) {
            throw new TransformerNotFoundException('The "transformer" key is required in context for TransformerPlaceholder.');
        }

        $transformer = $this->transformerRegistry->get($context['transformer']);

        $tinyWidth = $this->size;
        $tinyHeight = $this->size;

        $width = $transformation->width ?? 0;
        $height = $transformation->height ?? 0;

        if ($width > 0) {
            $tinyHeight = max(1, (int) round($tinyWidth * $height / $width));
        }

        return $transformer->url($image, new ImageTransformation(
            width: $tinyWidth,
            height: $tinyHeight,
            format: 'jpg',
            quality: $this->quality,
            fit: 'crop',
            blur: $this->blur,
        ), $context);
    }
}
