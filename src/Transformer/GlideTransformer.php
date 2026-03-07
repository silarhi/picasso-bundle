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
use LogicException;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\EncryptionException;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class GlideTransformer implements LocalTransformerInterface
{
    private const HMAC_LENGTH = 10;

    /**
     * @param array{enabled: bool, path: string}|null $publicCache
     */
    public function __construct(
        private UrlGeneratorInterface $router,
        private UrlEncryption $urlEncryption,
        private string $signKey,
        private string $cache,
        private string $driver = 'gd',
        private ?int $maxImageSize = null,
        private ?array $publicCache = null,
    ) {
    }

    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $path = $image->path ?? '';
        $glideParams = $this->mapToGlideParams($transformation);
        /** @var string $loaderName */
        $loaderName = $context['loader'] ?? throw new LogicException('The "loader" key is required in the context array.');
        /** @var string $transformerName */
        $transformerName = $context['transformer'] ?? throw new LogicException('The "transformer" key is required in the context array.');

        if ([] !== $image->metadata) {
            $glideParams['_metadata'] = $this->urlEncryption->encrypt(json_encode($image->metadata, \JSON_THROW_ON_ERROR));
        }

        if ($this->isPublicCacheEnabled()) {
            $paramsSegment = $this->buildParamsSegment($glideParams);
            $hmac = $this->buildHmac($paramsSegment);
            $format = isset($glideParams['fm']) ? (string) $glideParams['fm'] : pathinfo($path, \PATHINFO_EXTENSION);

            $cachedPath = $path . '/' . $paramsSegment . ',s_' . $hmac . '.' . $format;

            return $this->router->generate('picasso_image', [
                'transformer' => $transformerName,
                'loader' => $loaderName,
                'path' => $cachedPath,
            ], UrlGeneratorInterface::ABSOLUTE_PATH);
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

    public function serve(ServableLoaderInterface $loader, string $path, Request $request): Response
    {
        if ($this->isPublicCacheEnabled() && !$request->query->has('s')) {
            return $this->serveCached($loader, $path);
        }

        $params = $request->query->all();

        try {
            SignatureFactory::create($this->signKey)->validateRequest($path, $params);
        } catch (SignatureException $e) {
            throw new NotFoundHttpException('Invalid image signature.', $e);
        }

        return $this->doServe($loader, $path, $params);
    }

    public function isPublicCacheEnabled(): bool
    {
        return null !== $this->publicCache && $this->publicCache['enabled'];
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
     * Build an HMAC for a params segment using the sign key.
     */
    public function buildHmac(string $paramsSegment): string
    {
        return substr(hash_hmac('sha256', $paramsSegment, $this->signKey), 0, self::HMAC_LENGTH);
    }

    /**
     * Parse a params filename like "fit_contain,fm_webp,q_75,w_300,s_abc1234567.webp"
     * into its component parts.
     *
     * @return array{params: array<string, string>, paramsSegment: string, hmac: string, format: string}
     */
    public static function parseParamsFilename(string $filename): array
    {
        $dotPos = strrpos($filename, '.');
        if (false === $dotPos) {
            throw new NotFoundHttpException('Invalid cached image filename.');
        }

        $format = substr($filename, $dotPos + 1);
        $paramsString = substr($filename, 0, $dotPos);

        $pairs = explode(',', $paramsString);
        $hmac = null;
        $paramPairs = [];

        foreach ($pairs as $pair) {
            $separatorPos = strpos($pair, '_');
            if (false === $separatorPos) {
                throw new NotFoundHttpException('Invalid cached image param format.');
            }

            $key = substr($pair, 0, $separatorPos);
            $value = substr($pair, $separatorPos + 1);

            if ('s' === $key) {
                $hmac = $value;
            } else {
                $paramPairs[$key] = $value;
            }
        }

        if (null === $hmac) {
            throw new NotFoundHttpException('Missing signature in cached image URL.');
        }

        $segmentParts = [];
        foreach ($paramPairs as $key => $value) {
            $segmentParts[] = $key . '_' . $value;
        }
        $paramsSegment = implode(',', $segmentParts);

        return [
            'params' => $paramPairs,
            'paramsSegment' => $paramsSegment,
            'hmac' => $hmac,
            'format' => $format,
        ];
    }

    private function serveCached(ServableLoaderInterface $loader, string $path): Response
    {
        $lastSlash = strrpos($path, '/');
        if (false === $lastSlash) {
            throw new NotFoundHttpException('Invalid cached image path.');
        }

        $imagePath = substr($path, 0, $lastSlash);
        $paramsFilename = substr($path, $lastSlash + 1);

        $parsed = self::parseParamsFilename($paramsFilename);
        $this->validateHmac($parsed['paramsSegment'], $parsed['hmac']);

        return $this->doServe($loader, $imagePath, $parsed['params'], $paramsFilename);
    }

    private function validateHmac(string $paramsSegment, string $hmac): void
    {
        $expected = $this->buildHmac($paramsSegment);

        if (!hash_equals($expected, $hmac)) {
            throw new NotFoundHttpException('Invalid cached image signature.');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function doServe(ServableLoaderInterface $loader, string $path, array $params, ?string $cacheFilename = null): Response
    {
        if (isset($params['_metadata'])) {
            try {
                /** @var string $encryptedMetadata */
                $encryptedMetadata = $params['_metadata'];
                unset($params['_metadata']);
                $metadata = json_decode($this->urlEncryption->decrypt($encryptedMetadata), true, flags: \JSON_THROW_ON_ERROR);
            } catch (EncryptionException|JsonException $e) {
                throw new NotFoundHttpException('Invalid metadata parameter.', $e);
            }
        } else {
            $metadata = [];
        }

        /** @var array<string, mixed> $metadata */
        $source = $loader->getSource($metadata);

        // When public cache is enabled, Glide writes directly to the public
        // directory using a cache_path_callable that produces the readable
        // params-based filename.
        $cacheDir = $this->cache;
        $cachePathCallable = null;

        if (null !== $cacheFilename && $this->isPublicCacheEnabled()) {
            /** @var array{enabled: bool, path: string} $publicCache */
            $publicCache = $this->publicCache;
            $cacheDir = $publicCache['path'];
            $cachePathCallable = static fn (string $path, array $params): string => $path . '/' . $cacheFilename;
        }

        $serverConfig = [
            'source' => $source,
            'cache' => $cacheDir,
            'driver' => $this->driver,
            'response' => new SymfonyResponseFactory(),
        ];

        if (null !== $cachePathCallable) {
            $serverConfig['cache_path_callable'] = Closure::fromCallable($cachePathCallable);
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
