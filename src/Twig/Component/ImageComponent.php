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

namespace Silarhi\PicassoBundle\Twig\Component;

use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent('Picasso:Image', template: '@Picasso/components/Image.html.twig')]
class ImageComponent
{
    /** The image source path (null renders nothing). */
    public ?string $src = null;

    /** @var array<string, mixed> Extra context passed to the loader (e.g. entity, field for Vich). */
    public array $context = [];

    /** Explicit source width in pixels (skips metadata detection). */
    public ?int $sourceWidth = null;

    /** Explicit source height in pixels (skips metadata detection). */
    public ?int $sourceHeight = null;

    /** Display width in pixels. */
    public ?int $width = null;

    /** Display height in pixels. */
    public ?int $height = null;

    /** Responsive sizes attribute. */
    public ?string $sizes = null;

    /** Which loader to use ('filesystem', 'vich', 'flysystem'). */
    public ?string $loader = null;

    /** Which transformer to use ('glide', 'imgix'). */
    public ?string $transformer = null;

    /** Override image quality (1-100). */
    public ?int $quality = null;

    /** Fit mode ('contain', 'cover', 'fill', 'crop'). Null defaults to config value. */
    public ?string $fit = null;

    /** Enable/disable/select placeholder. Boolean to enable/disable, string to select a named placeholder. */
    public string|bool|null $placeholder = null;

    /** Literal placeholder data URI or URL, bypasses placeholder services. */
    public ?string $placeholderData = null;

    /** Mark as high-priority (above the fold): disables lazy loading and placeholder. */
    public bool $priority = false;

    /** Loading attribute ('lazy' or 'eager'). Resolved in PostMount: defaults to 'eager' when priority, 'lazy' otherwise. */
    public ?string $loading = null;

    /** Fetch priority attribute ('high', 'low', 'auto'). Resolved in PostMount: defaults to 'high' when priority. */
    public ?string $fetchPriority = null;

    /** Serve the image as-is, without optimization. */
    public bool $unoptimized = false;

    // --- Computed state (set in PostMount, used by template) ---

    /** @internal */
    public ?string $placeholderUri = null;

    /**
     * @internal
     *
     * @var ImageSource[]
     */
    public array $sources = [];

    /** @internal */
    public ?string $fallbackSrc = null;

    /** @internal */
    public ?string $fallbackSrcset = null;

    public function __construct(
        private readonly ImageHelperInterface $imageHelper,
    ) {
    }

    #[PostMount]
    public function computeImageData(): void
    {
        $data = $this->imageHelper->imageData(
            src: $this->src,
            width: $this->width,
            height: $this->height,
            sizes: $this->sizes,
            loader: $this->loader,
            transformer: $this->transformer,
            quality: $this->quality,
            fit: $this->fit,
            placeholder: $this->placeholder,
            placeholderData: $this->placeholderData,
            priority: $this->priority,
            loading: $this->loading,
            fetchPriority: $this->fetchPriority,
            unoptimized: $this->unoptimized,
            sourceWidth: $this->sourceWidth,
            sourceHeight: $this->sourceHeight,
            context: $this->context,
        );

        $this->placeholderUri = $data->placeholderUri;
        $this->sources = $data->sources;
        $this->fallbackSrc = $data->fallbackSrc;
        $this->fallbackSrcset = $data->fallbackSrcset;
        $this->width = $data->width;
        $this->height = $data->height;
        $this->loading = $data->loading;
        $this->fetchPriority = $data->fetchPriority;
    }
}
