<?php

namespace Silarhi\PicassoBundle\Transformer;

use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Responses\SymfonyResponseFactory;
use League\Glide\ServerFactory;
use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureFactory;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlideTransformer implements LocalTransformerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly string $signKey,
        private readonly string $cache,
        private readonly string $driver = 'gd',
        private readonly ?int $maxImageSize = null,
    ) {
    }

    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $path = $image->path ?? '';
        $glideParams = self::mapToGlideParams($transformation);
        $loaderName = $context['loader'] ?? 'filesystem';

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

        $serverConfig = [
            'source' => $loader->getSource(),
            'cache' => $this->cache,
            'driver' => $this->driver,
            'response' => new SymfonyResponseFactory($request),
        ];

        if ($this->maxImageSize !== null) {
            $serverConfig['max_image_size'] = $this->maxImageSize;
        }

        $server = ServerFactory::create($serverConfig);

        try {
            return $server->getImageResponse($path, $params);
        } catch (FileNotFoundException|\InvalidArgumentException $e) {
            throw new NotFoundHttpException('Image not found.', $e);
        }
    }

    /**
     * @return array<string, int|string>
     */
    private static function mapToGlideParams(ImageTransformation $transformation): array
    {
        $glide = [];

        if ($transformation->width !== null) {
            $glide['w'] = $transformation->width;
        }
        if ($transformation->height !== null) {
            $glide['h'] = $transformation->height;
        }
        if ($transformation->format !== null) {
            $glide['fm'] = $transformation->format;
        }

        $glide['q'] = $transformation->quality;
        $glide['fit'] = $transformation->fit;

        if ($transformation->blur !== null) {
            $glide['blur'] = $transformation->blur;
        }
        if ($transformation->dpr !== null) {
            $glide['dpr'] = $transformation->dpr;
        }

        return $glide;
    }
}
