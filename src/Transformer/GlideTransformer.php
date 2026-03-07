<?php

declare(strict_types=1);

/*
 * This file is part of the Picasso Bundle package.
 *
 * (c) SILARHI <dev@silarhi.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Silarhi\PicassoBundle\Transformer;

use Closure;
use InvalidArgumentException;
use JsonException;
use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Responses\SymfonyResponseFactory;
use League\Glide\ServerFactory;
use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureFactory;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\EncryptionException;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class GlideTransformer implements LocalTransformerInterface
{
    public function __construct(
        private UrlGeneratorInterface $router,
        private UrlEncryption $urlEncryption,
        private string $signKey,
        private string $cache,
        private string $driver = 'gd',
        private ?int $maxImageSize = null,
        private bool $publicCache = false,
    ) {
    }

    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $path = $image->path ?? '';
        $glideParams = $this->mapToGlideParams($transformation);
        /** @var string $loaderName */
        $loaderName = $context['loader'] ?? throw new LoaderNotFoundException('The "loader" key is required in the context array.');
        /** @var string $transformerName */
        $transformerName = $context['transformer'] ?? throw new TransformerNotFoundException('The "transformer" key is required in the context array.');

        if ([] !== $image->metadata) {
            $glideParams['_metadata'] = $this->urlEncryption->encrypt(json_encode($image->metadata, \JSON_THROW_ON_ERROR));
        }

        if ($this->isPublicCacheEnabled()) {
            // Move transformation params into the path, keep only _metadata as query param
            $paramsSegment = $this->buildParamsSegment($glideParams);
            $format = isset($glideParams['fm']) ? (string) $glideParams['fm'] : pathinfo($path, \PATHINFO_EXTENSION);
            $path = $path . '/' . $paramsSegment . '.' . $format;
            $glideParams = array_filter(
                $glideParams,
                static fn (string $key): bool => str_starts_with($key, '_'),
                \ARRAY_FILTER_USE_KEY,
            );
        }

        $signature = SignatureFactory::create($this->signKey)
            ->generateSignature($path, $glideParams);

        return $this->router->generate('picasso_image', [
            'transformer' => $transformerName,
            'loader' => $loaderName,
            'path' => $path,
            ...$glideParams,
            's' => $signature,
        ], UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    public function serve(ServableLoaderInterface $loader, string $path, Request $request, array $context = []): Response
    {
        $params = $request->query->all();
        $cacheFilename = null;
        $cachePrefix = null;

        try {
            SignatureFactory::create($this->signKey)->validateRequest($path, $params);
        } catch (SignatureException $e) {
            throw new ImageNotFoundException('Invalid image signature.', previous: $e);
        }

        if ($this->isPublicCacheEnabled()) {
            // Extract transformation params from the path
            $lastSlash = strrpos($path, '/');
            if (false === $lastSlash) {
                throw new ImageNotFoundException('Invalid cached image path.');
            }

            $cacheFilename = substr($path, $lastSlash + 1);
            $path = substr($path, 0, $lastSlash);

            ['params' => $cachedParams] = self::parseParamsFilename($cacheFilename);
            $params = [...$params, ...$cachedParams];

            // Include transformer/loader in cache path so it mirrors the URL structure
            /** @var string $transformerName */
            $transformerName = $context['transformer'] ?? '';
            /** @var string $loaderName */
            $loaderName = $context['loader'] ?? '';
            $cachePrefix = $transformerName . '/' . $loaderName;
        }

        if (isset($params['_metadata'])) {
            try {
                /** @var string $encryptedMetadata */
                $encryptedMetadata = $params['_metadata'];
                unset($params['_metadata']);
                $metadata = json_decode($this->urlEncryption->decrypt($encryptedMetadata), true, flags: \JSON_THROW_ON_ERROR);
            } catch (EncryptionException|JsonException $e) {
                throw new ImageNotFoundException('Invalid metadata parameter.', previous: $e);
            }
        } else {
            $metadata = [];
        }

        /** @var array<string, mixed> $metadata */
        $source = $loader->getSource($metadata);

        $serverConfig = [
            'source' => $source,
            'cache' => $this->cache,
            'driver' => $this->driver,
            'response' => new SymfonyResponseFactory($request),
        ];

        if (null !== $cacheFilename) {
            $serverConfig['cache_path_callable'] = Closure::fromCallable(
                static fn (string $path, array $params): string => ($cachePrefix ? $cachePrefix . '/' : '') . $path . '/' . $cacheFilename,
            );
        }

        if (null !== $this->maxImageSize) {
            $serverConfig['max_image_size'] = $this->maxImageSize;
        }

        $server = ServerFactory::create($serverConfig);

        try {
            /** @var Response $response */
            $response = $server->getImageResponse($path, $params);

            return $response;
        } catch (FileNotFoundException|InvalidArgumentException $e) {
            throw new ImageNotFoundException('Image not found.', previous: $e);
        }
    }

    public function isPublicCacheEnabled(): bool
    {
        return $this->publicCache;
    }

    /**
     * Build the params segment from Glide params (excluding _metadata and s).
     *
     * @param array<string, int|string> $glideParams
     */
    public function buildParamsSegment(array $glideParams): string
    {
        $filtered = array_filter(
            $glideParams,
            static fn (string $key): bool => '_metadata' !== $key && 's' !== $key,
            \ARRAY_FILTER_USE_KEY,
        );

        ksort($filtered);

        $parts = [];
        foreach ($filtered as $key => $value) {
            $parts[] = $key . '_' . $value;
        }

        return implode(',', $parts);
    }

    /**
     * Parse a params filename like "fit_contain,fm_webp,q_75,w_300.webp"
     * into its component parts.
     *
     * @return array{params: array<string, string>, paramsSegment: string, format: string}
     */
    public static function parseParamsFilename(string $filename): array
    {
        $dotPos = strrpos($filename, '.');
        if (false === $dotPos) {
            throw new ImageNotFoundException('Invalid cached image filename.');
        }

        $format = substr($filename, $dotPos + 1);
        $paramsString = substr($filename, 0, $dotPos);

        $pairs = explode(',', $paramsString);
        $paramPairs = [];

        foreach ($pairs as $pair) {
            $separatorPos = strpos($pair, '_');
            if (false === $separatorPos) {
                throw new ImageNotFoundException('Invalid cached image param format.');
            }

            $key = substr($pair, 0, $separatorPos);
            $value = substr($pair, $separatorPos + 1);
            $paramPairs[$key] = $value;
        }

        return [
            'params' => $paramPairs,
            'paramsSegment' => $paramsString,
            'format' => $format,
        ];
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
