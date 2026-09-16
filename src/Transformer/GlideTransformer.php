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

use function in_array;

use InvalidArgumentException;

use function is_scalar;

use JsonException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Responses\SymfonyResponseFactory;
use League\Glide\Server;
use League\Glide\ServerFactory;
use League\Glide\Signatures\Signature;
use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureFactory;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\EncryptionException;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Exception\PurgeException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlEncryption;

use function sprintf;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * @phpstan-import-type TransformerContext from ImageTransformerInterface
 */
final readonly class GlideTransformer implements LocalTransformerInterface, PurgableTransformerInterface
{
    /**
     * Transformation params {@see mapToGlideParams()} can emit. Used to tell a
     * public-cache params segment apart from a plain image filename.
     */
    private const TRANSFORMATION_PARAMS = ['w', 'h', 'fm', 'q', 'fit', 'blur', 'dpr'];

    /**
     * How long clients and CDNs may cache the redirect away from a legacy URL.
     * Deliberately not a year: the redirect target embeds the current URL scheme,
     * so a shorter window keeps a future scheme change from being pinned.
     */
    private const LEGACY_REDIRECT_MAX_AGE = 2592000;

    private Signature $signature;
    private Server $server;

    public function __construct(
        private UrlGeneratorInterface $router,
        private UrlEncryption $urlEncryption,
        string $signKey,
        string $cache,
        string $driver,
        ?int $maxImageSize,
        private bool $publicCache,
        ?FlysystemRegistry $flysystemRegistry = null,
    ) {
        $this->signature = SignatureFactory::create($signKey);

        $resolvedCache = null !== $flysystemRegistry && $flysystemRegistry->has($cache)
            ? $flysystemRegistry->get($cache)
            : $cache;

        $serverConfig = [
            'source' => $resolvedCache,
            'cache' => $resolvedCache,
            'driver' => $driver,
        ];

        if (null !== $maxImageSize) {
            $serverConfig['max_image_size'] = $maxImageSize;
        }

        $this->server = ServerFactory::create($serverConfig);
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

        $signature = $this->signature
            ->generateSignature($path, $glideParams);

        $url = $this->router->generate('picasso_image', [
            'transformer' => $transformerName,
            'loader' => $loaderName,
            'path' => $path,
            ...$glideParams,
            's' => $signature,
        ], UrlGeneratorInterface::ABSOLUTE_PATH);

        // The public-cache params segment is comma-separated, and Symfony's URL
        // generator leaves commas raw. Consumers that split a srcset attribute on
        // "," instead of on whitespace then tear the URL apart and request the
        // trailing chunk (e.g. "w_720.jpg") as a relative path. Percent-encoding
        // is transparent — routing, the Glide signature and static serving all
        // decode %2C back to "," — and leaves nothing for them to split on.
        return str_replace(',', '%2C', $url);
    }

    public function serve(ServableLoaderInterface $loader, string $path, Request $request, array $context = []): Response
    {
        $params = $request->query->all();
        $cachePathCallable = null;

        try {
            $this->signature->validateRequest($path, $params);
        } catch (SignatureException $e) {
            throw new ImageNotFoundException('Invalid image signature.', $e->getCode(), previous: $e);
        }

        if ($this->isPublicCacheEnabled() && $this->isLegacyRequest($path, $params)) {
            // URLs minted before public cache was enabled keep their transformation
            // params in the query string. Their signature still validates, but they
            // can never be served straight from the cache bucket, so point clients
            // at the canonical path-based URL instead of 404ing on them.
            $response = new RedirectResponse(
                $this->buildCanonicalUrl($path, $params, $context),
                Response::HTTP_MOVED_PERMANENTLY,
            );
            $response->setPublic();
            $response->setMaxAge(self::LEGACY_REDIRECT_MAX_AGE);

            return $response;
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

            $transformer = $this;
            /** @phpstan-ignore closure.useThis (Glide Server rebinds $this on the closure via Closure::bind) */
            $cachePathCallable = function (string $path) use ($transformer, $cacheFilename, $context): string {
                return $transformer->computeCachePath($path, $cacheFilename, $context);
            };
        }

        if (isset($params['_metadata'])) {
            try {
                /** @var string $encryptedMetadata */
                $encryptedMetadata = $params['_metadata'];
                unset($params['_metadata']);
                $metadata = json_decode($this->urlEncryption->decrypt($encryptedMetadata), true, flags: \JSON_THROW_ON_ERROR);
            } catch (EncryptionException|JsonException $e) {
                throw new ImageNotFoundException('Invalid metadata parameter.', $e->getCode(), previous: $e);
            }
        } else {
            $metadata = [];
        }

        /** @var array<string, mixed> $metadata */
        $source = $loader->getSource($metadata);
        if ($source instanceof FilesystemOperator) {
            $sourceFilesystem = $source;
        } else {
            /** @var string $source */
            $sourceFilesystem = new Filesystem(new LocalFilesystemAdapter($source));
        }

        $this->server->setSource($sourceFilesystem);
        $this->server->setResponseFactory(new SymfonyResponseFactory($request));
        $this->server->setCachePathCallable($cachePathCallable);

        try {
            /** @var Response $response */
            $response = $this->server->getImageResponse($path, $params);

            return $response;
        } catch (FileNotFoundException|InvalidArgumentException $e) {
            throw new ImageNotFoundException('Image not found.', $e->getCode(), previous: $e);
        }
    }

    /**
     * @param array<string, string> $context
     */
    public function computeCachePath(string $path, string $cacheFilename, array $context): string
    {
        // Include transformer/loader in cache path so it mirrors the URL structure
        /** @var string $transformerName */
        $transformerName = $context['transformer'] ?? throw new TransformerNotFoundException('The "transformer" key is required in the context array.');
        /** @var string $loaderName */
        $loaderName = $context['loader'] ?? throw new LoaderNotFoundException('The "loader" key is required in the context array.');
        $cachePrefix = $transformerName . '/' . $loaderName;

        return $cachePrefix . '/' . $path . '/' . $cacheFilename;
    }

    public function purge(string $path, array $context = []): void
    {
        $cachePath = $path;

        if ($this->isPublicCacheEnabled()) {
            /** @var string $transformerName */
            $transformerName = $context['transformer'] ?? throw new TransformerNotFoundException('The "transformer" key is required in the context array for public cache purge.');
            /** @var string $loaderName */
            $loaderName = $context['loader'] ?? throw new LoaderNotFoundException('The "loader" key is required in the context array for public cache purge.');

            $cachePath = $transformerName . '/' . $loaderName . '/' . ltrim($path, '/');
        }

        try {
            $this->server->deleteCache($cachePath);
        } catch (Throwable $e) {
            throw new PurgeException(sprintf('Failed to purge cache for "%s".', $path), $e->getCode(), previous: $e);
        }
    }

    public function isPublicCacheEnabled(): bool
    {
        return $this->publicCache;
    }

    /**
     * Whether the request targets a URL generated before public cache was enabled,
     * i.e. one carrying its transformation params in the query string.
     *
     * @param array<string, mixed> $params
     */
    private function isLegacyRequest(string $path, array $params): bool
    {
        // url() strips every transformation param from the query in public-cache
        // mode, so seeing one here means the URL predates that switch.
        foreach (self::TRANSFORMATION_PARAMS as $key) {
            if (isset($params[$key])) {
                return true;
            }
        }

        // An untransformed legacy URL carries no params at all, and then ends with
        // the image filename where a params segment would otherwise sit.
        $lastSlash = strrpos($path, '/');

        return !$this->looksLikeParamsFilename(false === $lastSlash ? $path : substr($path, $lastSlash + 1));
    }

    /**
     * Whether a filename parses as a params segment whose every key is a known
     * transformation param. The key check is what keeps an ordinary filename such
     * as "my_photo.jpg" from being mistaken for one.
     */
    private function looksLikeParamsFilename(string $filename): bool
    {
        $dotPos = strrpos($filename, '.');
        if (false === $dotPos || 0 === $dotPos) {
            return false;
        }

        foreach (explode(',', substr($filename, 0, $dotPos)) as $pair) {
            $separatorPos = strpos($pair, '_');
            if (false === $separatorPos) {
                return false;
            }

            if (!in_array(substr($pair, 0, $separatorPos), self::TRANSFORMATION_PARAMS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rebuild the current-scheme URL for a legacy request, so it can be redirected.
     *
     * @param array<string, mixed> $params
     * @param TransformerContext   $context
     */
    private function buildCanonicalUrl(string $path, array $params, array $context): string
    {
        $metadata = [];
        if (isset($params['_metadata'])) {
            try {
                /** @var string $encryptedMetadata */
                $encryptedMetadata = $params['_metadata'];
                /** @var array<string, mixed> $metadata */
                $metadata = json_decode($this->urlEncryption->decrypt($encryptedMetadata), true, flags: \JSON_THROW_ON_ERROR);
            } catch (EncryptionException|JsonException $e) {
                throw new ImageNotFoundException('Invalid metadata parameter.', $e->getCode(), previous: $e);
            }
        }

        return $this->url(
            new Image(path: $path, metadata: $metadata),
            new ImageTransformation(
                width: self::intParam($params, 'w'),
                height: self::intParam($params, 'h'),
                format: self::stringParam($params, 'fm'),
                quality: self::intParam($params, 'q'),
                fit: self::stringParam($params, 'fit'),
                blur: self::intParam($params, 'blur'),
                dpr: self::intParam($params, 'dpr'),
            ),
            $context,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function intParam(array $params, string $key): ?int
    {
        return isset($params[$key]) && is_scalar($params[$key]) ? (int) $params[$key] : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function stringParam(array $params, string $key): ?string
    {
        return isset($params[$key]) && is_scalar($params[$key]) ? (string) $params[$key] : null;
    }

    /**
     * Build the params segment from Glide params (excluding _metadata and s).
     *
     * @param TransformerParams $glideParams
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
     * @return TransformerParams
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

        if (null !== $transformation->quality) {
            $glide['q'] = $transformation->quality;
        }
        if (null !== $transformation->fit) {
            $glide['fit'] = $transformation->fit;
        }

        if (null !== $transformation->blur) {
            $glide['blur'] = $transformation->blur;
        }
        if (null !== $transformation->dpr) {
            $glide['dpr'] = $transformation->dpr;
        }

        return $glide;
    }
}
