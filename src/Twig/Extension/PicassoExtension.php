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
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PicassoExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImageHelperInterface $imageHelper,
    ) {
    }

    public function getFilters(): array
    {
        return [
            // @internal: used by @Picasso/image.html.twig
            new TwigFilter('picasso_escape_urls', self::escapeUrls(...), ['needs_environment' => true]),
        ];
    }

    /**
     * HTML-escapes generated URLs (src, srcset) faster than Twig's escaper.
     *
     * Generated URLs are ASCII whose only HTML-special character is "&": for those,
     * replacing "&" gives exactly what htmlspecialchars() gives, at a fraction of its
     * cost on long srcset attributes. Anything else is returned as is, and Twig's
     * autoescaping escapes it as usual.
     *
     * @internal
     */
    public static function escapeUrls(Environment $env, ?string $urls): Markup|string|null
    {
        if (null === $urls || 'UTF-8' !== $env->getCharset() || 1 === preg_match('/[^\x20\x21\x23-\x26\x28-\x3B\x3D\x3F-\x7E]/', $urls)) {
            return $urls;
        }

        return new Markup(str_replace('&', '&amp;', $urls), 'UTF-8');
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('picasso_image_url', $this->imageHelper->imageUrl(...)),
            new TwigFunction('picasso_image', $this->renderImage(...), [
                'needs_environment' => true,
                'is_safe' => ['html'],
            ]),
        ];
    }

    /**
     * Render a responsive <picture> element (same output as the <Picasso:Image> Twig component).
     *
     * Intended for consumers that do not install symfony/ux-twig-component.
     *
     * @param array<string, mixed>       $context         Extra context passed to the loader (e.g. entity, field for Vich).
     * @param array<string, scalar|null> $attributes      extra HTML attributes forwarded to the inner <img> tag (alt, class, id, data-*, …)
     * @param string|null                $route           Application route serving the image (through ImageServer) instead of the bundle's
     * @param array<string, mixed>       $routeParameters Parameters of that route
     */
    public function renderImage(
        Environment $env,
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
    ): string {
        $data = $this->imageHelper->imageData(
            src: $src,
            width: $width,
            height: $height,
            sizes: $sizes,
            loader: $loader,
            transformer: $transformer,
            quality: $quality,
            fit: $fit,
            placeholder: $placeholder,
            placeholderData: $placeholderData,
            priority: $priority,
            loading: $loading,
            fetchPriority: $fetchPriority,
            unoptimized: $unoptimized,
            sourceWidth: $sourceWidth,
            sourceHeight: $sourceHeight,
            resolveMetadata: $resolveMetadata,
            context: $context,
            attributes: $attributes,
            route: $route,
            routeParameters: $routeParameters,
        );

        return $env->render('@Picasso/image.html.twig', [
            'data' => $data,
            'attributes' => $attributes,
        ]);
    }
}
