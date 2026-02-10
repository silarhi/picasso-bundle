<?php

namespace Silarhi\PicassoBundle\Twig\Extension;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Url\ImageUrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PicassoExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContainerInterface $providers,
        private readonly string $defaultProvider,
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
     *   {{ picasso_image_url('uploads/photo.jpg', {width: 300, format: 'webp', provider: 'imgix'}) }}
     */
    public function imageUrl(string $path, array $params = []): string
    {
        $providerName = $params['provider'] ?? $this->defaultProvider;
        unset($params['provider']);

        /** @var ImageUrlGeneratorInterface $urlGenerator */
        $urlGenerator = $this->providers->get($providerName);

        return $urlGenerator->generate($path, new ImageParams(
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
