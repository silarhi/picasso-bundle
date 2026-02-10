<?php

namespace Silarhi\PicassoBundle\Twig\Extension;

use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Url\ImageUrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PicassoExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImageUrlGeneratorInterface $urlGenerator,
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
     *   {{ picasso_image_url('uploads/photo.jpg', {width: 300, height: 200, format: 'webp'}) }}
     */
    public function imageUrl(string $path, array $params = []): string
    {
        return $this->urlGenerator->generate($path, new ImageParams(
            width: $params['width'] ?? null,
            height: $params['height'] ?? null,
            format: $params['format'] ?? null,
            quality: $params['quality'] ?? null,
            fit: $params['fit'] ?? 'contain',
            blur: $params['blur'] ?? null,
            dpr: $params['dpr'] ?? null,
        ));
    }
}
