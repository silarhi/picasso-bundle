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
use Silarhi\PicassoBundle\Exception\InvalidTransformationException;
use Silarhi\PicassoBundle\Exception\PresetNotFoundException;

interface ImageHelperInterface
{
    /**
     * Generate a single image URL with named parameters.
     *
     * @param array<string, mixed>      $context         Extra context passed to the loader (e.g. entity, field for Vich).
     * @param string|null               $route           Application route serving the image (through ImageServer) instead of the
     *                                                   bundle's: generated URLs point at it, with the transformation in the query
     * @param array<string, mixed>      $routeParameters Parameters of that route
     * @param array<string, int|string> $modifiers       Further manipulations, in Glide's vocabulary (flip, or, bri…)
     * @param string|null               $preset          Named preset (the "presets" configuration) filling the options
     *                                                   not given
     *
     * @throws InvalidRouteException          When the loader is private and no route is given, the transformer cannot
     *                                        serve a route, or a route parameter clashes with a transformation param
     * @throws InvalidTransformationException When the transformer cannot express a modifier
     * @throws PresetNotFoundException        When the preset is not configured
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
        array $modifiers = [],
        ?string $preset = null,
    ): string;

    /**
     * Compute all image rendering data (dimensions, sources, placeholder, loading attributes).
     *
     * Returns an immutable DTO suitable for both Twig component rendering and JSON API responses.
     *
     * @param array<string, mixed>                $context         Extra context for the loader
     * @param array<string, scalar|null>          $attributes      Extra HTML attributes (alt, class, …)
     * @param string|null                         $route           Application route serving the image (through ImageServer) instead
     *                                                             of the bundle's: every srcset candidate and the transformer
     *                                                             placeholder point at it, with the transformation in the query
     * @param array<string, mixed>                $routeParameters Parameters of that route
     * @param array<string, int|string>           $modifiers       Further manipulations, in Glide's vocabulary (flip, or, bri…)
     * @param string|null                         $preset          Named preset (the "presets" configuration) filling the options
     *                                                             not given
     * @param array<string, array<string, mixed>> $artDirection    Art-directed sources by media query, rendered before the
     *                                                             image's own: each takes src, context, width, height,
     *                                                             sourceWidth, sourceHeight, sizes, quality, fit, modifiers
     *                                                             and preset, and inherits from the image what it omits
     *
     * @throws InvalidRouteException          When the loader is private and no route is given, the transformer cannot
     *                                        serve a route, or a route parameter clashes with a transformation param
     * @throws InvalidTransformationException When the transformer cannot express a modifier, or an art-direction entry
     *                                        has an unknown key or a value of the wrong type
     * @throws PresetNotFoundException        When a preset is not configured
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
        array $modifiers = [],
        ?string $preset = null,
        array $artDirection = [],
    ): ImageRenderData;

    /**
     * Generate a CSS image-set() of an image, for backgrounds: one candidate per configured format and density.
     *
     * The width and height are CSS pixels; each density multiplies them, capped to the source width when it is known.
     *
     * @param list<int>                 $densities       Pixel densities of the candidates (1x, 2x…)
     * @param array<string, int|string> $modifiers       Further manipulations, in Glide's vocabulary (flip, or, bri…)
     * @param array<string, mixed>      $context         Extra context for the loader
     * @param array<string, mixed>      $routeParameters Parameters of the route
     *
     * @return string The image-set() value, e.g. image-set(url('…') 1x type('image/avif'), …)
     *
     * @throws InvalidRouteException          When the loader is private and no route is given, or the transformer
     *                                        cannot serve a route
     * @throws InvalidTransformationException When the transformer cannot express a modifier
     * @throws PresetNotFoundException        When the preset is not configured
     */
    public function imageSet(
        ?string $src = null,
        ?int $width = null,
        ?int $height = null,
        array $densities = [1, 2],
        ?string $loader = null,
        ?string $transformer = null,
        ?int $quality = null,
        ?string $fit = null,
        array $modifiers = [],
        ?int $sourceWidth = null,
        ?int $sourceHeight = null,
        ?bool $resolveMetadata = null,
        array $context = [],
        ?string $route = null,
        array $routeParameters = [],
        ?string $preset = null,
    ): string;
}
