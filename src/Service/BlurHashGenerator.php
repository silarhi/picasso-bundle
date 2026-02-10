<?php

namespace Silarhi\PicassoBundle\Service;

/**
 * Generates base64-encoded blur placeholder images.
 *
 * Implementations can use any backend (Glide, Cloudinary, etc.)
 * to produce a tiny blurred preview image.
 */
interface BlurHashGenerator
{
    public function isEnabled(): bool;

    /**
     * Generate a base64-encoded data URI of a tiny blurred version of the image.
     *
     * @param string   $path   The resolved source image path
     * @param int|null $width  Source width (for aspect ratio)
     * @param int|null $height Source height (for aspect ratio)
     *
     * @return string|null The data URI, or null if disabled or failed
     */
    public function generate(string $path, ?int $width = null, ?int $height = null): ?string;
}
