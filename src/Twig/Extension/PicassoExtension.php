<?php

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
        $loader = $params['loader'] ?? null;
        $transformer = $params['transformer'] ?? null;
        unset($params['loader'], $params['transformer']);

        $reference = new ImageReference($path);

        $transformation = new ImageTransformation(
            width: $params['width'] ?? null,
            height: $params['height'] ?? null,
            format: $params['format'] ?? null,
            quality: $params['quality'] ?? 75,
            fit: $params['fit'] ?? 'contain',
            blur: $params['blur'] ?? null,
            dpr: $params['dpr'] ?? null,
        );

        return $this->pipeline->url($reference, $transformation, $loader, $transformer);
    }
}
