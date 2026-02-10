<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\ImageParams;

/**
 * Imgix-backed loader.
 *
 * Maps agnostic ImageParams to imgix query parameters and generates
 * URLs pointing to the imgix CDN.
 *
 * @see https://docs.imgix.com/apis/rendering
 */
class ImgixLoader implements ImageLoaderInterface
{
    public function __construct(
        private readonly string $domain,
        private readonly ?string $signKey = null,
        private readonly bool $useHttps = true,
    ) {
    }

    public function getUrl(string $path, ImageParams $params): string
    {
        $imgixParams = self::mapToImgixParams($params);

        $path = '/'.ltrim($path, '/');
        $queryString = http_build_query($imgixParams);

        if ($this->signKey !== null) {
            $signature = self::generateSignature($this->signKey, $path, $queryString);
            $queryString .= ($queryString !== '' ? '&' : '').'s='.$signature;
        }

        $scheme = $this->useHttps ? 'https' : 'http';

        return $scheme.'://'.$this->domain.$path.($queryString !== '' ? '?'.$queryString : '');
    }

    /**
     * @return array<string, int|string>
     */
    private static function mapToImgixParams(ImageParams $params): array
    {
        $imgix = [];

        if ($params->width !== null) {
            $imgix['w'] = $params->width;
        }
        if ($params->height !== null) {
            $imgix['h'] = $params->height;
        }
        if ($params->format !== null) {
            $imgix['fm'] = $params->format;
        }
        if ($params->quality !== null) {
            $imgix['q'] = $params->quality;
        }

        $imgix['fit'] = self::mapFit($params->fit);

        if ($params->blur !== null) {
            $imgix['blur'] = $params->blur;
        }
        if ($params->dpr !== null) {
            $imgix['dpr'] = $params->dpr;
        }

        return $imgix;
    }

    /**
     * @see https://docs.imgix.com/apis/rendering/size/resize-fit-mode
     */
    private static function mapFit(string $fit): string
    {
        return match ($fit) {
            'contain' => 'clip',
            'cover', 'crop' => 'crop',
            'fill' => 'fill',
            default => $fit,
        };
    }

    /**
     * @see https://docs.imgix.com/setup/securing-images
     */
    private static function generateSignature(string $signKey, string $path, string $queryString): string
    {
        $data = $signKey.$path;
        if ($queryString !== '') {
            $data .= '?'.$queryString;
        }

        return md5($data);
    }
}
