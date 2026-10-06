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

use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Exception\InvalidRouteException;

interface ImageHelperInterface
{
    /**
     * Generate a single image URL with named parameters.
     *
     * @param array<string, mixed> $context         Extra context passed to the loader (e.g. entity, field for Vich).
     * @param string|null          $route           Application route serving the image (through ImageServer) instead of the
     *                                              bundle's: generated URLs point at it, with the transformation in the query
     * @param array<string, mixed> $routeParameters Parameters of that route
     *
     * @throws InvalidRouteException When the loader is private and no route is given, the transformer cannot serve a
     *                               route, or a route parameter clashes with a transformation param
     */
    public function imageUrl(
        string $path,
        ?int $width = null,
        ?int $height = null,
        ?string $format = null,
        ?int $quality = null,
        ?string $fit = null,
        ?int $blur = null,
        ?int $dpr = null,
        ?string $loader = null,
        ?string $transformer = null,
        array $context = [],
        ?string $route = null,
        array $routeParameters = [],
    ): string;

    /**
     * Compute all image rendering data (dimensions, sources, placeholder, loading attributes).
     *
     * Returns an immutable DTO suitable for both Twig component rendering and JSON API responses.
     *
     * @param array<string, mixed>       $context         Extra context for the loader
     * @param array<string, scalar|null> $attributes      Extra HTML attributes (alt, class, …)
     * @param string|null                $route           Application route serving the image (through ImageServer) instead
     *                                                    of the bundle's: every srcset candidate and the transformer
     *                                                    placeholder point at it, with the transformation in the query
     * @param array<string, mixed>       $routeParameters Parameters of that route
     *
     * @throws InvalidRouteException When the loader is private and no route is given, the transformer cannot serve a
     *                               route, or a route parameter clashes with a transformation param
     */
    public function imageData(
        ?string $src = null,
        ?int $width = null,
        ?int $height = null,
        ?string $sizes = null,
        ?string $loader = null,
        ?string $transformer = null,
        ?int $quality = null,
        ?string $fit = null,
        string|bool|null $placeholder = null,
        ?string $placeholderData = null,
        bool $priority = false,
        ?string $loading = null,
        ?string $fetchPriority = null,
        bool $unoptimized = false,
        ?int $sourceWidth = null,
        ?int $sourceHeight = null,
        ?bool $resolveMetadata = null,
        array $context = [],
        array $attributes = [],
        ?string $route = null,
        array $routeParameters = [],
    ): ImageRenderData;
}
