<?php

namespace Silarhi\PicassoBundle\Service;

use League\Glide\Signatures\SignatureFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class UrlGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly string $signKey,
    ) {
    }

    /**
     * Generate a signed URL for a single image transformation.
     *
     * @param string $path   The source image path (relative to Glide source root)
     * @param array  $params Glide transformation params (w, h, fm, q, fit, blur, etc.)
     */
    public function generate(string $path, array $params = []): string
    {
        $signature = SignatureFactory::create($this->signKey)
            ->generateSignature($path, $params);

        return $this->router->generate('picasso_image', array_merge(
            ['path' => $path],
            $params,
            ['s' => $signature],
        ), UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
