<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\ImageParams;

/**
 * Generates optimized image URLs from a resolved path.
 *
 * Each implementation maps ImageParams to a provider-specific URL format
 * (Glide, imgix, Cloudinary, custom CDN, etc.).
 */
interface ImageLoaderInterface
{
    public function getUrl(string $path, ImageParams $params): string;
}
