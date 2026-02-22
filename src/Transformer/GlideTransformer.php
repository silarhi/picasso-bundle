<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Transformer;

use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Responses\SymfonyResponseFactory;
use League\Glide\ServerFactory;
use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureFactory;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlideTransformer implements LocalTransformerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly UrlEncryption $urlEncryption,
        private readonly string $signKey,
        private readonly string $cache,
        private readonly string $driver = 'gd',
        private readonly ?int $maxImageSize = null,
    ) {
    }

    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $path = $image->path ?? '';
        $glideParams = $this->mapToGlideParams($transformation);
        $loaderName = $context['loader'] ?? 'filesystem';

        if (isset($image->metadata['_source'])) {
            /** @var string $source */
            $source = $image->metadata['_source'];
            $glideParams['_source'] = $this->urlEncryption->encrypt($source);
        }

        $signature = SignatureFactory::create($this->signKey)
            ->generateSignature($path, $glideParams);

        return $this->router->generate('picasso_image', array_merge(
            [
                'transformer' => 'glide',
                'loader' => $loaderName,
                'path' => $path,
            ],
            $glideParams,
            ['s' => $signature],
        ), UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    public function serve(ServableLoaderInterface $loader, string $path, Request $request): Response
    {
        $params = $request->query->all();

        try {
            SignatureFactory::create($this->signKey)
                ->validateRequest($path, $params);
        } catch (SignatureException $e) {
            throw new NotFoundHttpException('Invalid image signature.', $e);
        }

        // Decrypt source from URL metadata if present, otherwise fall back to loader
        if (isset($params['_source'])) {
            try {
                /** @var string $encryptedSource */
                $encryptedSource = $params['_source'];
                $source = $this->urlEncryption->decrypt($encryptedSource);
            } catch (\RuntimeException $e) {
                throw new NotFoundHttpException('Invalid source parameter.', $e);
            }
        } else {
            $source = $loader->getSource();
        }

        $serverConfig = [
            'source' => $source,
            'cache' => $this->cache,
            'driver' => $this->driver,
            'response' => new SymfonyResponseFactory($request),
        ];

        if (null !== $this->maxImageSize) {
            $serverConfig['max_image_size'] = $this->maxImageSize;
        }

        $server = ServerFactory::create($serverConfig);

        try {
            /** @var Response $response */
            $response = $server->getImageResponse($path, $params);

            return $response;
        } catch (FileNotFoundException|\InvalidArgumentException $e) {
            throw new NotFoundHttpException('Image not found.', $e);
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function mapToGlideParams(ImageTransformation $transformation): array
    {
        $glide = [];

        if (null !== $transformation->width) {
            $glide['w'] = $transformation->width;
        }
        if (null !== $transformation->height) {
            $glide['h'] = $transformation->height;
        }
        if (null !== $transformation->format) {
            $glide['fm'] = $transformation->format;
        }

        $glide['q'] = $transformation->quality;
        $glide['fit'] = $transformation->fit;

        if (null !== $transformation->blur) {
            $glide['blur'] = $transformation->blur;
        }
        if (null !== $transformation->dpr) {
            $glide['dpr'] = $transformation->dpr;
        }

        return $glide;
    }
}
