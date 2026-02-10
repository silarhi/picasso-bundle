<?php

namespace Silarhi\PicassoBundle\Twig\Extension;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PicassoExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContainerInterface $loaders,
        private readonly string $defaultLoader,
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
     *   {{ picasso_image_url('uploads/photo.jpg', {width: 300, format: 'webp', loader: 'imgix'}) }}
     */
    public function imageUrl(string $path, array $params = []): string
    {
        $loaderName = $params['loader'] ?? $this->defaultLoader;
        unset($params['loader']);

        /** @var ImageLoaderInterface $loader */
        $loader = $this->loaders->get($loaderName);

        return $loader->getUrl($path, new ImageParams(
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
