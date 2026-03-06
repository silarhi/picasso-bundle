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

namespace Silarhi\PicassoBundle\Service;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;

readonly class ImagePipeline
{
    public function __construct(
        private LoaderRegistry $loaderRegistry,
        private TransformerRegistry $transformerRegistry,
        private ?string $defaultLoader,
        private ?string $defaultTransformer,
    ) {
    }

    /**
     * Full pipeline: load image + generate transformed URL.
     */
    public function url(
        ImageReference $reference,
        ImageTransformation $transformation,
        ?string $loader = null,
        ?string $transformer = null,
    ): string {
        $loaderName = $this->resolveLoaderName($loader);
        $transformerName = $this->resolveTransformerName($transformer);

        $image = $this->loaderRegistry->get($loaderName)->load($reference);
        $imageTransformer = $this->transformerRegistry->get($transformerName);

        return $imageTransformer->url($image, $transformation, ['loader' => $loaderName, 'transformer' => $transformerName]);
    }

    /**
     * Load an image from a reference (without transformation).
     */
    public function load(
        ImageReference $reference,
        ?string $loader = null,
        bool $withMetadata = false,
    ): Image {
        return $this->loaderRegistry->get($this->resolveLoaderName($loader))->load($reference, $withMetadata);
    }

    public function resolveLoaderName(?string $loader = null): string
    {
        return $loader ?? $this->defaultLoader ?? throw new LoaderNotFoundException('No loader specified and no default_loader configured.');
    }

    public function resolveTransformerName(?string $transformer = null): string
    {
        return $transformer ?? $this->defaultTransformer ?? throw new TransformerNotFoundException('No transformer specified and no default_transformer configured.');
    }
}
