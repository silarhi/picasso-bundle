<?php

namespace Silarhi\PicassoBundle\Transformer;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;

/**
 * @see https://docs.imgix.com/apis/rendering
 */
class ImgixTransformer implements ImageTransformerInterface
{
    public function __construct(
        private readonly string $domain,
        private readonly ?string $signKey = null,
        private readonly bool $useHttps = true,
    ) {
    }

    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $imgixParams = self::mapToImgixParams($transformation);

        $path = '/'.ltrim($image->path ?? '', '/');
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
    private static function mapToImgixParams(ImageTransformation $transformation): array
    {
        $imgix = [];

        if ($transformation->width !== null) {
            $imgix['w'] = $transformation->width;
        }
        if ($transformation->height !== null) {
            $imgix['h'] = $transformation->height;
        }
        if ($transformation->format !== null) {
            $imgix['fm'] = $transformation->format;
        }

        $imgix['q'] = $transformation->quality;
        $imgix['fit'] = self::mapFit($transformation->fit);

        if ($transformation->blur !== null) {
            $imgix['blur'] = $transformation->blur;
        }
        if ($transformation->dpr !== null) {
            $imgix['dpr'] = $transformation->dpr;
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
