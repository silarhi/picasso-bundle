<?php

namespace Silarhi\PicassoBundle\Service;

/**
 * No-op blur placeholder generator.
 *
 * Used when the image provider is a remote CDN (imgix, Cloudinary, etc.)
 * that does not support server-side blur generation.
 */
class NullBlurHashGenerator implements BlurHashGenerator
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function generate(string $path, ?int $width = null, ?int $height = null): ?string
    {
        return null;
    }
}
