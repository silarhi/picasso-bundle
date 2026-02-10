<?php

namespace Silarhi\PicassoBundle\Url;

use Silarhi\PicassoBundle\Dto\ImageParams;

/**
 * Generates image URLs from a source path and agnostic transformation parameters.
 *
 * Each implementation maps ImageParams to a provider-specific URL format
 * (Glide, Cloudinary, Imgix, custom CDN, etc.).
 */
interface ImageUrlGeneratorInterface
{
    /**
     * Generate a URL for the given source path and transformation parameters.
     */
    public function generate(string $path, ImageParams $params): string;
}
