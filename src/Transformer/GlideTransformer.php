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
     * @param array{enabled: bool, path: string, url_prefix: string}|null $publicCache
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
            return $this->generatePublicCacheUrl($path, $glideParams, $loaderName, $transformerName);
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
        $params = $request->query->all();

        try {
            SignatureFactory::create($this->signKey)->validateRequest($path, $params);
        } catch (SignatureException $e) {
            throw new NotFoundHttpException('Invalid image signature.', $e);
        }

        return $this->doServe($loader, $path, $params);
    }

    /**
     * Serve a cached image by parsing params from the path segment and writing the result to the public cache directory.
     */
    public function serveCached(ServableLoaderInterface $loader, string $imagePath, string $paramsFilename): Response
    {
        $parsed = self::parseParamsFilename($paramsFilename);
        $this->validateHmac($parsed['paramsSegment'], $parsed['hmac']);

        $glideParams = $parsed['params'];

        $response = $this->doServe($loader, $imagePath, $glideParams);

        $this->writePublicCache($imagePath, $paramsFilename, $response);

        return $response;
    }

    public function isPublicCacheEnabled(): bool
    {
        return null !== $this->publicCache && $this->publicCache['enabled'];
    }

    /**
     * @return array{enabled: bool, path: string, url_prefix: string}|null
     */
    public function getPublicCache(): ?array
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

        // Rebuild the params segment without the HMAC for verification
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

    /**
     * @param array<string, int|string> $glideParams
     */
    private function generatePublicCacheUrl(string $path, array $glideParams, string $loaderName, string $transformerName): string
    {
        $paramsSegment = $this->buildParamsSegment($glideParams);
        $hmac = $this->buildHmac($paramsSegment);

        $format = isset($glideParams['fm']) ? (string) $glideParams['fm'] : pathinfo($path, \PATHINFO_EXTENSION);

        /** @var array{enabled: bool, path: string, url_prefix: string} $publicCache */
        $publicCache = $this->publicCache;

        return rtrim($publicCache['url_prefix'], '/') . '/' . $transformerName . '/' . $loaderName . '/' . $path . '/' . $paramsSegment . ',s_' . $hmac . '.' . $format;
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
    private function doServe(ServableLoaderInterface $loader, string $path, array $params): Response
    {
        // Decrypt source from URL metadata if present, otherwise fall back to loader
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
        $serverConfig = [
            'source' => $source,
            'cache' => $this->cache,
            'driver' => $this->driver,
            'response' => new SymfonyResponseFactory(),
        ];

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

    private function writePublicCache(string $imagePath, string $paramsFilename, Response $response): void
    {
        if (!$this->isPublicCacheEnabled()) {
            return;
        }

        /** @var array{enabled: bool, path: string, url_prefix: string} $publicCache */
        $publicCache = $this->publicCache;
        $cacheDir = rtrim($publicCache['path'], '/') . '/' . $imagePath;

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0o777, true);
        }

        $cacheFile = $cacheDir . '/' . $paramsFilename;
        file_put_contents($cacheFile, $response->getContent());
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
