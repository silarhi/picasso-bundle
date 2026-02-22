<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Twig\Extension;

use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PicassoExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImagePipeline $pipeline,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('picasso_image_url', $this->imageUrl(...)),
        ];
    }

    /**
     * Generate a single image URL from agnostic parameters.
     *
     * Usage in Twig:
     *   {{ picasso_image_url('uploads/photo.jpg', {width: 300, format: 'webp'}) }}
     *   {{ picasso_image_url('uploads/photo.jpg', {width: 300, loader: 'vich', transformer: 'imgix'}) }}
     *
     * @param array<string, mixed> $params
     */
    public function imageUrl(string $path, array $params = []): string
    {
        $loader = isset($params['loader']) && \is_string($params['loader']) ? $params['loader'] : null;
        $transformer = isset($params['transformer']) && \is_string($params['transformer']) ? $params['transformer'] : null;
        unset($params['loader'], $params['transformer']);

        $reference = new ImageReference($path);

        $width = $params['width'] ?? null;
        $height = $params['height'] ?? null;
        $format = $params['format'] ?? null;
        $quality = $params['quality'] ?? 75;
        $fit = $params['fit'] ?? 'contain';
        $blur = $params['blur'] ?? null;
        $dpr = $params['dpr'] ?? null;

        $transformation = new ImageTransformation(
            width: \is_int($width) ? $width : null,
            height: \is_int($height) ? $height : null,
            format: \is_string($format) ? $format : null,
            quality: \is_int($quality) ? $quality : 75,
            fit: \is_string($fit) ? $fit : 'contain',
            blur: \is_int($blur) ? $blur : null,
            dpr: \is_int($dpr) ? $dpr : null,
        );

        return $this->pipeline->url($reference, $transformation, $loader, $transformer);
    }
}
