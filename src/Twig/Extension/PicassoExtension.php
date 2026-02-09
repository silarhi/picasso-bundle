<?php

namespace Silarhi\PicassoBundle\Twig\Extension;

use Silarhi\PicassoBundle\Service\UrlGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PicassoExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGenerator $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('picasso_image_url', $this->imageUrl(...)),
        ];
    }

    /**
     * Generate a single signed image URL.
     *
     * Usage in Twig:
     *   {{ picasso_image_url('uploads/photo.jpg', {w: 300, h: 200, fm: 'webp'}) }}
     */
    public function imageUrl(string $path, array $params = []): string
    {
        return $this->urlGenerator->generate($path, $params);
    }
}
