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
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Exception\InvalidRouteException;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Transformer\PurgableTransformerInterface;

use function sprintf;

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
     *
     * @param string|null          $route           Application route serving the image (through ImageServer) instead of the bundle's
     * @param array<string, mixed> $routeParameters Parameters of that route
     *
     * @throws InvalidRouteException When the loader is private and no route is given, or the transformer cannot serve a route
     */
    public function url(
        ImageReference $reference,
        ImageTransformation $transformation,
        ?string $loader = null,
        ?string $transformer = null,
        ?string $route = null,
        array $routeParameters = [],
    ): string {
        $loaderName = $this->resolveLoaderName($loader);
        $transformerName = $this->resolveTransformerName($transformer, $loaderName);

        $image = $this->loaderRegistry->get($loaderName)->load($reference);
        $imageTransformer = $this->transformerRegistry->get($transformerName);

        return $imageTransformer->url($image, $transformation, (new TransformerContextFactory($this->loaderRegistry, $this->transformerRegistry))->create($image, $loaderName, $transformerName, $route, $routeParameters));
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

    /**
     * Purge all cached variants of an image.
     *
     * @throws InvalidConfigurationException When the transformer does not support purging
     */
    public function purge(
        string $path,
        ?string $loader = null,
        ?string $transformer = null,
    ): void {
        $loaderName = $this->resolveLoaderName($loader);
        $transformerName = $this->resolveTransformerName($transformer, $loaderName);

        $imageTransformer = $this->transformerRegistry->get($transformerName);

        if (!$imageTransformer instanceof PurgableTransformerInterface) {
            throw new InvalidConfigurationException(sprintf('Transformer "%s" does not support cache purging.', $transformerName));
        }

        $imageTransformer->purge($path, ['loader' => $loaderName, 'transformer' => $transformerName]);
    }

    public function resolveLoaderName(?string $loader = null): string
    {
        return $loader ?? $this->defaultLoader ?? throw new LoaderNotFoundException('No loader specified and no default_loader configured.');
    }

    /**
     * The given transformer, else the loader's default_transformer, else the global default_transformer.
     *
     * Rendering, URL generation, purges and ImageServer all resolve the transformer here: Glide URLs
     * are signed with the transformer's key, so generating and serving must pick the same one.
     *
     * @param string|null $loaderName Resolved name of the requested loader (see resolveLoaderName()), never
     *                                the delegate a chain hands the image to; null skips the loader's default
     *
     * @throws TransformerNotFoundException When no transformer is given and none is configured by default
     */
    public function resolveTransformerName(?string $transformer = null, ?string $loaderName = null): string
    {
        return $transformer
            ?? (null !== $loaderName ? $this->loaderRegistry->getDefaultTransformer($loaderName) : null)
            ?? $this->defaultTransformer
            ?? throw new TransformerNotFoundException('No transformer specified and no default_transformer configured.');
    }
}
