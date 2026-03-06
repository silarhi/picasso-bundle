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

use LogicException;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

class ImagePipeline
{
    public function __construct(
        private readonly ContainerInterface $loaders,
        private readonly ContainerInterface $transformers,
        private readonly ?string $defaultLoader,
        private readonly string $defaultTransformer,
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
        $loaderName = $loader ?? $this->defaultLoader ?? throw new LogicException('No loader specified and no default_loader configured.');
        $transformerName = $transformer ?? $this->defaultTransformer;

        /** @var ImageLoaderInterface $imageLoader */
        $imageLoader = $this->loaders->get($loaderName);
        $image = $imageLoader->load($reference);

        /** @var ImageTransformerInterface $imageTransformer */
        $imageTransformer = $this->transformers->get($transformerName);

        return $imageTransformer->url($image, $transformation, ['loader' => $loaderName]);
    }

    /**
     * Load an image from a reference (without transformation).
     */
    public function load(
        ImageReference $reference,
        ?string $loader = null,
        bool $withMetadata = false,
    ): Image {
        $loaderName = $loader ?? $this->defaultLoader ?? throw new LogicException('No loader specified and no default_loader configured.');

        /** @var ImageLoaderInterface $imageLoader */
        $imageLoader = $this->loaders->get($loaderName);

        return $imageLoader->load($reference, $withMetadata);
    }
}
