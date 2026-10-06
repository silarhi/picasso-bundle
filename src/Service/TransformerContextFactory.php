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
use Silarhi\PicassoBundle\Exception\InvalidRouteException;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;

use function sprintf;

/**
 * Builds the context transformers generate the URLs of a loaded image with.
 *
 * @internal
 *
 * @phpstan-import-type TransformerContext from ImageTransformerInterface
 */
final readonly class TransformerContextFactory
{
    public function __construct(
        private LoaderRegistry $loaderRegistry,
        private TransformerRegistry $transformerRegistry,
    ) {
    }

    /**
     * The context names the loader that serves the image (the one a delegating loader
     * handed it to) and, with a route, the application route serving it instead of
     * the bundle's.
     *
     * @param array<string, mixed> $routeParameters
     *
     * @return TransformerContext
     *
     * @throws InvalidRouteException When the loader is private and no route is given, or the transformer cannot serve a route
     */
    public function create(
        Image $image,
        string $loaderName,
        string $transformerName,
        ?string $route = null,
        array $routeParameters = [],
    ): array {
        $servingLoader = $image->loader ?? $loaderName;
        $context = ['loader' => $servingLoader, 'transformer' => $transformerName];

        if (null === $route) {
            if ($this->loaderRegistry->isPrivate($loaderName) || $this->loaderRegistry->isPrivate($servingLoader)) {
                throw new InvalidRouteException(sprintf('Loader "%s" is private: only your own routes serve its images. Pass "route" (and "routeParameters") naming the route that serves them with ImageServer.', $servingLoader));
            }

            return $context;
        }

        if (!$this->transformerRegistry->get($transformerName) instanceof LocalTransformerInterface) {
            throw new InvalidRouteException(sprintf('Transformer "%s" cannot serve images from route "%s": only local transformers (e.g. Glide) serve images through your routes.', $transformerName, $route));
        }

        return [...$context, 'route' => $route, 'route_parameters' => $routeParameters];
    }
}
