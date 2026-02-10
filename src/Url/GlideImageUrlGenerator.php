<?php

namespace Silarhi\PicassoBundle\Url;

use League\Glide\Signatures\SignatureFactory;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Glide-backed implementation.
 *
 * Maps agnostic ImageParams to Glide query parameters and generates
 * signed URLs via the Symfony router.
 */
class GlideImageUrlGenerator implements ImageUrlGeneratorInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly string $signKey,
    ) {
    }

    public function generate(string $path, ImageParams $params): string
    {
        $glideParams = self::mapToGlideParams($params);

        $signature = SignatureFactory::create($this->signKey)
            ->generateSignature($path, $glideParams);

        return $this->router->generate('picasso_image', array_merge(
            ['path' => $path],
            $glideParams,
            ['s' => $signature],
        ), UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /**
     * Map agnostic ImageParams to Glide-specific query parameters.
     *
     * @return array<string, int|string>
     */
    private static function mapToGlideParams(ImageParams $params): array
    {
        $glide = [];

        if ($params->width !== null) {
            $glide['w'] = $params->width;
        }
        if ($params->height !== null) {
            $glide['h'] = $params->height;
        }
        if ($params->format !== null) {
            $glide['fm'] = $params->format;
        }
        if ($params->quality !== null) {
            $glide['q'] = $params->quality;
        }

        $glide['fit'] = $params->fit;

        if ($params->blur !== null) {
            $glide['blur'] = $params->blur;
        }
        if ($params->dpr !== null) {
            $glide['dpr'] = $params->dpr;
        }

        return $glide;
    }
}
