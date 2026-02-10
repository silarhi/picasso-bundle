<?php

namespace Silarhi\PicassoBundle\Service;

use League\Glide\Server;
use Silarhi\PicassoBundle\Dto\BlurPlaceholderConfig;

/**
 * Glide-backed blur placeholder generator.
 *
 * Uses Glide's server directly to generate a tiny blurred image,
 * avoiding an HTTP round-trip. Glide caches the result on disk.
 */
class GlideBlurHashGenerator implements BlurHashGenerator
{
    public function __construct(
        private readonly Server $glideServer,
        private readonly BlurPlaceholderConfig $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled;
    }

    public function generate(string $path, ?int $width = null, ?int $height = null): ?string
    {
        if (!$this->config->enabled) {
            return null;
        }

        $tinyWidth = $this->config->size;
        $tinyHeight = $this->config->size;

        if ($width !== null && $height !== null && $width > 0) {
            $tinyHeight = max(1, (int) round($tinyWidth * $height / $width));
        }

        $params = [
            'w' => $tinyWidth,
            'h' => $tinyHeight,
            'fit' => 'crop',
            'blur' => $this->config->blur,
            'q' => $this->config->quality,
            'fm' => 'jpg',
        ];

        try {
            $cachedPath = $this->glideServer->makeImage($path, $params);
            $cache = $this->glideServer->getCache();
            $content = $cache->read($cachedPath);

            if ($content === false || $content === '') {
                return null;
            }

            return 'data:image/jpeg;base64,'.base64_encode($content);
        } catch (\Throwable) {
            return null;
        }
    }
}
