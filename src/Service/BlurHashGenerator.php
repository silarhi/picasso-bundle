<?php

namespace Silarhi\PicassoBundle\Service;

use League\Glide\Server;

class BlurHashGenerator
{
    /**
     * @param array{enabled: bool, size: int, blur: int, quality: int} $config
     */
    public function __construct(
        private readonly Server $glideServer,
        private readonly array $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'];
    }

    /**
     * Generate a base64-encoded data URI of a tiny blurred version of the image.
     *
     * Uses Glide's server directly to avoid an HTTP round-trip.
     * Glide caches the tiny image on disk so subsequent calls are instant.
     */
    public function generate(string $path, ?int $width = null, ?int $height = null): ?string
    {
        if (!$this->config['enabled']) {
            return null;
        }

        $tinyWidth = $this->config['size'];
        $tinyHeight = $this->config['size'];

        if ($width !== null && $height !== null && $width > 0) {
            $tinyHeight = max(1, (int) round($tinyWidth * $height / $width));
        }

        $params = [
            'w' => $tinyWidth,
            'h' => $tinyHeight,
            'fit' => 'crop',
            'blur' => $this->config['blur'],
            'q' => $this->config['quality'],
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
