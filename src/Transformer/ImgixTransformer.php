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

/**
 * @phpstan-import-type TransformerContext from ImageTransformerInterface
 *
 * @see https://docs.imgix.com/apis/rendering
 */
final readonly class ImgixTransformer implements ImageTransformerInterface
{
    public function __construct(
        private string $baseUrl,
        private ?string $signKey = null,
    ) {
    }

    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $imgixParams = $this->mapToImgixParams($transformation);

        $path = '/' . ltrim($image->path ?? '', '/');
        $queryString = http_build_query($imgixParams);

        if (null !== $this->signKey) {
            $signature = $this->generateSignature($this->signKey, $path, $queryString);
            $queryString .= ('' !== $queryString ? '&' : '') . 's=' . $signature;
        }

        return rtrim($this->baseUrl, '/') . $path . ('' !== $queryString ? '?' . $queryString : '');
    }

    /**
     * @return TransformerParams
     */
    private function mapToImgixParams(ImageTransformation $transformation): array
    {
        $imgix = [];

        if (null !== $transformation->width) {
            $imgix['w'] = $transformation->width;
        }
        if (null !== $transformation->height) {
            $imgix['h'] = $transformation->height;
        }
        if (null !== $transformation->format) {
            $imgix['fm'] = $transformation->format;
        }

        if (null !== $transformation->quality) {
            $imgix['q'] = $transformation->quality;
        }
        $imgix['fit'] = $this->mapFit($transformation->fit);

        if (null !== $transformation->blur) {
            $imgix['blur'] = $transformation->blur;
        }
        if (null !== $transformation->dpr) {
            $imgix['dpr'] = $transformation->dpr;
        }

        return $imgix;
    }

    /**
     * @see https://docs.imgix.com/apis/rendering/size/resize-fit-mode
     */
    private function mapFit(string $fit): string
    {
        return match ($fit) {
            'contain' => 'clip',
            'cover', 'crop' => 'crop',
            'fill' => 'fill',
            default => $fit,
        };
    }

    /**
     * @see https://docs.imgix.com/setup/securing-images
     */
    private function generateSignature(string $signKey, string $path, string $queryString): string
    {
        $data = $signKey . $path;
        if ('' !== $queryString) {
            $data .= '?' . $queryString;
        }

        return md5($data);
    }
}
