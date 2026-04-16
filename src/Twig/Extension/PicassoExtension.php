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
     * @param array<string, mixed>       $context    Extra context passed to the loader (e.g. entity, field for Vich).
     * @param array<string, scalar|null> $attributes extra HTML attributes forwarded to the inner <img> tag (alt, class, id, data-*, …)
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
        );

        return $env->render('@Picasso/image.html.twig', [
            'data' => $data,
            'attributes' => $attributes,
        ]);
    }
}
