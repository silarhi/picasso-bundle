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

use Intervention\Image\Exceptions\DecoderException;
use InvalidArgumentException;

use function is_array;
use function is_scalar;
use function is_string;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Filesystem\FilesystemException;
use League\Glide\Server;
use League\Glide\ServerFactory;
use League\Glide\Signatures\Signature;
use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureFactory;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\InvalidRouteException;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Exception\PurgeException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Exception\UndecodableImageException;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Silarhi\PicassoBundle\Source\ImageSourceFlysystemAdapter;

use function sprintf;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
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
     * Route parameters that would collide with the query string of a routed image
     * URL: the transformation params, the signature, and the URL fragment.
     */
    private const RESERVED_ROUTE_PARAMETERS = [...self::TRANSFORMATION_PARAMS, 's', '_fragment'];

    /**
     * Reserved params segment of an untransformed image in public-cache mode.
     * There, the source path is a directory holding the variants: a file cannot
     * live at that same path, and web servers treat a directory path specially
     * (Apache's DirectorySlash redirects, nginx tries "$uri/"). So an untransformed
     * image still needs a filename inside that folder: "photo.jpg/_untransformed.jpg".
     */
    private const UNTRANSFORMED_PARAMS_SEGMENT = '_untransformed';

    /**
     * How long clients and CDNs may cache the redirect away from a legacy URL.
     * Deliberately not a year: the redirect target embeds the current URL scheme,
     * so a shorter window keeps a future scheme change from being pinned.
     */
    private const LEGACY_REDIRECT_MAX_AGE = 2592000;

    /**
     * What Glide lets through when the source bytes are not a decodable image
     * (truncated upload, PDF saved under an image name...). Matched with
     * instanceof rather than caught: each supported league/glide major pulls a
     * different intervention/image major, so only one of these classes exists at
     * a time, and Glide's @throws does not declare them.
     */
    private const DECODING_EXCEPTIONS = [
        DecoderException::class, // intervention/image 3 and 4
        'Intervention\\Image\\Exception\\NotReadableException', // intervention/image 2
    ];

    /**
     * Intervention drivers known by name, beyond the "gd" and "imagick" Glide resolves itself.
     */
    private const DRIVERS = [
        'vips' => 'Intervention\\Image\\Drivers\\Vips\\Driver', // intervention/image-driver-vips
    ];

    /**
     * Prefix of the lock serializing the renders of a variant across processes.
     */
    private const LOCK_PREFIX = 'picasso.glide.';

    /**
     * Microseconds between two attempts to take the render lock of a variant
     * another process is rendering (what Symfony's blocking acquire waits).
     */
    private const LOCK_POLL_INTERVAL = 100_000;

    private Signature $signature;
    private Server $server;
    private ?string $baseUrl;
    private string $cachePrefix;
    private FilesystemOperator $cacheStorage;

    /**
     * Local directory a miss is rendered to when cache writes are deferred. Only
     * created by a miss, and deleted by the writer once the render is moved.
     */
    private ?string $renderDirectory;

    /**
     * @param string                   $driver              "gd", "imagick", "vips" (needs intervention/image-driver-vips) or an Intervention driver class
     * @param string|null              $baseUrl             Scheme and host (e.g. a CDN) prepended to generated URLs; null keeps them host-relative
     * @param string                   $cachePrefix         Public-cache mode only: path prepended to every cache key, so keys can mirror the
     *                                                      URL path (e.g. "image" when the bundle routes are served under /image)
     * @param DeferredCacheWriter|null $deferredCacheWriter When set, a miss is rendered to local disk and moved to the
     *                                                      cache storage after the response has been sent
     * @param LockFactory|null         $lockFactory         When set, concurrent requests for the same missing variant render it
     *                                                      once: the others wait for that render and serve it
     * @param float                    $lockTtl             Seconds a render lock outlives a crashed renderer
     * @param float                    $lockWait            Seconds a miss waits for the render in progress of the same
     *                                                      variant before rendering it itself
     */
    public function __construct(
        private UrlGeneratorInterface $router,
        private UrlAliases $urlAliases,
        string $signKey,
        string $cache,
        string $driver,
        ?int $maxImageSize,
        private bool $publicCache,
        ?FlysystemRegistry $flysystemRegistry = null,
        ?string $baseUrl = null,
        string $cachePrefix = '',
        private ?DeferredCacheWriter $deferredCacheWriter = null,
        private ?LockFactory $lockFactory = null,
        private float $lockTtl = 30.0,
        private float $lockWait = 10.0,
    ) {
        $this->signature = SignatureFactory::create($signKey);
        $this->baseUrl = null !== $baseUrl && '' !== $baseUrl ? rtrim($baseUrl, '/') : null;
        $this->cachePrefix = trim($cachePrefix, '/');

        $resolvedCache = null !== $flysystemRegistry && $flysystemRegistry->has($cache)
            ? $flysystemRegistry->get($cache)
            : $cache;

        $serverConfig = [
            'source' => $resolvedCache,
            'cache' => $resolvedCache,
            'driver' => self::DRIVERS[$driver] ?? $driver,
        ];

        if (null !== $maxImageSize) {
            $serverConfig['max_image_size'] = $maxImageSize;
        }

        $this->server = ServerFactory::create($serverConfig);
        $this->cacheStorage = $this->server->getCache();
        // One directory per instance, so per worker thread. Not created here: most
        // instances only generate URLs or serve hits, and each would leave one behind.
        $this->renderDirectory = null !== $deferredCacheWriter
            ? sys_get_temp_dir() . '/picasso-glide-' . bin2hex(random_bytes(8))
            : null;
    }

    /**
     * The Intervention driver class a driver name stands for, when Glide does not
     * resolve it itself. The class exists only when its package is installed.
     */
    public static function driverClass(string $driver): ?string
    {
        return self::DRIVERS[$driver] ?? null;
    }

    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $path = $image->path ?? '';
        $glideParams = $this->mapToGlideParams($transformation);
        /** @var string $loaderName */
        $loaderName = $context['loader'] ?? throw new LoaderNotFoundException('The "loader" key is required in the context array.');
        /** @var string $transformerName */
        $transformerName = $context['transformer'] ?? throw new TransformerNotFoundException('The "transformer" key is required in the context array.');

        if (isset($context['route'])) {
            return $this->routeUrl($path, $glideParams, $transformerName, $context);
        }

        if ($this->isPublicCacheEnabled()) {
            // Move transformation params into the path, leaving only the signature in the query
            $paramsSegment = $this->buildParamsSegment($glideParams);
            if ('' === $paramsSegment) {
                $paramsSegment = self::UNTRANSFORMED_PARAMS_SEGMENT;
            }
            $format = isset($glideParams['fm']) ? (string) $glideParams['fm'] : pathinfo($path, \PATHINFO_EXTENSION);
            $path = $path . '/' . $paramsSegment . '.' . $format;
            $glideParams = [];
        }

        $signature = $this->signature
            ->generateSignature($path, $glideParams);

        $url = $this->router->generate('picasso_image', [
            'transformer' => $this->urlAliases->transformerSegment($transformerName),
            'loader' => $this->urlAliases->loaderSegment($loaderName),
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
        $url = str_replace(',', '%2C', $url);

        return null !== $this->baseUrl ? $this->baseUrl . $url : $url;
    }

    /**
     * The URL of a transformation on an application route, served through ImageServer.
     *
     * Route parameters that are not placeholders of the route path end up in the
     * query string next to the transformation params, and serving validates the
     * signature against the whole query: so the signature covers the query string
     * the router actually generated, not only the transformation params.
     *
     * @param TransformerParams  $glideParams
     * @param TransformerContext $context
     *
     * @throws InvalidRouteException When public cache is enabled, or a route parameter clashes with a transformation param
     */
    private function routeUrl(string $path, array $glideParams, string $transformerName, array $context): string
    {
        $route = $context['route'];
        $routeParameters = $context['route_parameters'] ?? [];
        if (!is_string($route) || !is_array($routeParameters)) {
            throw new InvalidRouteException('The "route" context key must be a route name and "route_parameters" an array.');
        }

        if ($this->isPublicCacheEnabled()) {
            // Public-cache variants are stored at paths the web server serves without
            // running the application, so it would answer them without your route.
            throw new InvalidRouteException(sprintf('Transformer "%s" cannot serve images from route "%s": its public cache is served without running your route. Use a Glide transformer without "public_cache".', $transformerName, $route));
        }

        $clashes = array_intersect(array_map(strval(...), array_keys($routeParameters)), self::RESERVED_ROUTE_PARAMETERS);
        if ([] !== $clashes) {
            throw new InvalidRouteException(sprintf('Route "%s": the parameters "%s" are reserved for the image transformation. Rename them in your route.', $route, implode('", "', $clashes)));
        }

        $url = $this->router->generate($route, [...$routeParameters, ...$glideParams], UrlGeneratorInterface::ABSOLUTE_PATH);

        $query = parse_url($url, \PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $params);

        return $url . (is_string($query) ? '&' : '?') . http_build_query(['s' => $this->signature->generateSignature($path, $params)]);
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
            return $this->permanentRedirect($this->buildCanonicalUrl($path, $params, $context));
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

        $responseFactory = new GlideResponseFactory($request);
        $this->server->setSource(new Filesystem(new ImageSourceFlysystemAdapter($loader->getSource())));
        $this->server->setResponseFactory($responseFactory);
        $this->server->setCachePathCallable($cachePathCallable);
        // The server is shared by every request of a long-running process, so
        // each request picks its cache storage.
        $this->server->setCache($this->cacheStorage);

        try {
            $cachePath = $this->server->getCachePath($path, $params);
        } catch (FileNotFoundException $e) {
            throw new ImageNotFoundException('Image not found.', $e->getCode(), previous: $e);
        }

        // A hit never goes through Glide, which would ask the storage whether the
        // variant exists before reading it.
        $response = $responseFactory->fromCache($this->cacheStorage, $cachePath);
        if (null !== $response) {
            return $response;
        }

        $lock = $this->lockFactory?->createLock(self::LOCK_PREFIX . hash('xxh128', $cachePath), $this->lockTtl);
        if (null !== $lock && !$lock->acquire()) {
            // Another process is rendering this variant: wait for it, then serve its render
            if ($this->acquireWithinWait($lock)) {
                $response = $responseFactory->fromCache($this->cacheStorage, $cachePath);
                if (null !== $response) {
                    $lock->release();

                    return $response;
                }
            } else {
                // That render outlasts the wait (a stalled upload, a slow storage): render the
                // variant here, without the lock, rather than run out of the request's time
                $lock = null;
            }
        }

        // With deferred writes, a miss is rendered to local disk and moved to the
        // cache storage on kernel.terminate.
        $renderStorage = null !== $this->renderDirectory
            ? new Filesystem(new LocalFilesystemAdapter($this->renderDirectory))
            : null;
        if (null !== $renderStorage) {
            $this->server->setCache($renderStorage);
        }

        $lockHandedOver = false;

        try {
            /** @var Response $response */
            $response = $this->server->getImageResponse($path, $params);

            if (null !== $renderStorage && null !== $this->deferredCacheWriter) {
                // The variant reaches the cache storage after the response is sent:
                // the lock must keep the waiting requests away until then.
                $this->deferredCacheWriter->defer($renderStorage, $this->cacheStorage, $cachePath, $lock);
                $lockHandedOver = true;
            }

            return $response;
        } catch (FileNotFoundException|InvalidArgumentException $e) {
            throw new ImageNotFoundException('Image not found.', $e->getCode(), previous: $e);
        } catch (FilesystemException $e) {
            // Concurrent requests for the same variant all render it and race to
            // write it; object stores may reject the losers (e.g. S3-compatible
            // storages answering 409 to a conflicting conditional write). Once
            // the variant is there, serve it rather than fail the request.
            return $responseFactory->fromCache($this->server->getCache(), $cachePath) ?? throw $e;
        } catch (Throwable $e) {
            if (!$this->isDecodingFailure($e)) {
                throw $e;
            }

            throw new UndecodableImageException('Source image could not be decoded.', $e->getCode(), previous: $e);
        } finally {
            if (!$lockHandedOver) {
                $lock?->release();
            }
        }
    }

    /**
     * Takes a lock another process holds, waiting at most lock.wait for it.
     * LockInterface::acquire(true) has no timeout: it would wait as long as the
     * holder keeps the lock, up to its ttl, and past the request's
     * max_execution_time, a fatal error that also ends a long-running worker.
     */
    private function acquireWithinWait(LockInterface $lock): bool
    {
        $deadline = microtime(true) + $this->lockWait;

        while (($remaining = $deadline - microtime(true)) > 0) {
            usleep((int) min(self::LOCK_POLL_INTERVAL, $remaining * 1_000_000));

            if ($lock->acquire()) {
                return true;
            }
        }

        return false;
    }

    private function isDecodingFailure(Throwable $e): bool
    {
        foreach (self::DECODING_EXCEPTIONS as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
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

        return $this->publicCacheDirectory($transformerName, $loaderName, $path) . '/' . $cacheFilename;
    }

    /**
     * Where the public-cache variants of an image live: "[prefix/]transformer/loader/path",
     * with the URL aliases of the transformer and loader, as in the URL path.
     */
    private function publicCacheDirectory(string $transformerName, string $loaderName, string $path): string
    {
        $directory = $this->urlAliases->transformerSegment($transformerName) . '/' . $this->urlAliases->loaderSegment($loaderName) . '/' . ltrim($path, '/');

        return '' !== $this->cachePrefix ? $this->cachePrefix . '/' . $directory : $directory;
    }

    public function purge(string $path, array $context = []): void
    {
        $cachePath = $path;

        if ($this->isPublicCacheEnabled()) {
            /** @var string $transformerName */
            $transformerName = $context['transformer'] ?? throw new TransformerNotFoundException('The "transformer" key is required in the context array for public cache purge.');
            /** @var string $loaderName */
            $loaderName = $context['loader'] ?? throw new LoaderNotFoundException('The "loader" key is required in the context array for public cache purge.');

            $cachePath = $this->publicCacheDirectory($transformerName, $loaderName, $path);
        }

        // deleteCache() removes the folder of Glide's default cache path for
        // $cachePath. A public-cache serve() leaves its own cache path callable on
        // the shared server: in a long-running process, it would send the purge to
        // the folder of whatever variant was served last instead. A deferred-write
        // serve() leaves the local render directory as the cache for the same reason.
        $this->server->setCachePathCallable(null);
        $this->server->setCache($this->cacheStorage);

        try {
            $purged = $this->server->deleteCache($cachePath);
        } catch (Throwable $e) {
            throw new PurgeException(sprintf('Failed to purge cache for "%s".', $path), $e->getCode(), previous: $e);
        }

        // Glide turns the storage's Flysystem exception into false, so there is
        // no previous exception to chain. A never-cached image is not a failure:
        // deleting a missing folder succeeds.
        if (!$purged) {
            throw new PurgeException(sprintf('Failed to purge cache for "%s": the cache storage could not delete it.', $path));
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
     *
     * @throws ImageNotFoundException when the path ends with a dot-leading filename
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
        $filename = false === $lastSlash ? $path : substr($path, $lastSlash + 1);

        // A dot-leading name (".jpg", or "." without extension) is what url() used
        // to emit for an untransformed image. It is neither: redirecting it would
        // loop, as each hop appends one more segment.
        if (str_starts_with($filename, '.')) {
            throw new ImageNotFoundException('Invalid cached image filename.');
        }

        return !$this->looksLikeParamsFilename($filename);
    }

    /**
     * Whether a filename parses as a params segment whose every key is a known
     * transformation param, or as the untransformed one. The key check is what
     * keeps an ordinary filename such as "my_photo.jpg" from being mistaken for one.
     */
    private function looksLikeParamsFilename(string $filename): bool
    {
        $dotPos = strrpos($filename, '.');
        if (false === $dotPos || 0 === $dotPos) {
            return false;
        }

        if (self::UNTRANSFORMED_PARAMS_SEGMENT === substr($filename, 0, $dotPos)) {
            return true;
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
     * Redirects a signed request to the same image and transformation under another loader.
     *
     * Any query param the signature covers is accepted, and only the transformation
     * is carried over: the target URL is generated anew, signed and in the current
     * URL scheme (path-based params in public-cache mode).
     *
     * @param TransformerContext $context The context of the request; its "loader" is replaced
     *
     * @throws ImageNotFoundException When the signature is invalid
     *
     * @internal Used to redirect 1.x URLs, see LegacyUrlController
     */
    public function redirectToLoader(string $loader, string $path, Request $request, array $context = []): RedirectResponse
    {
        $params = $request->query->all();

        try {
            $this->signature->validateRequest($path, $params);
        } catch (SignatureException $e) {
            throw new ImageNotFoundException('Invalid image signature.', $e->getCode(), previous: $e);
        }

        if ($this->isPublicCacheEnabled() && !$this->isLegacyRequest($path, $params)) {
            $lastSlash = strrpos($path, '/');
            if (false === $lastSlash) {
                throw new ImageNotFoundException('Invalid cached image path.');
            }

            ['params' => $cachedParams] = self::parseParamsFilename(substr($path, $lastSlash + 1));
            $params = [...$params, ...$cachedParams];
            $path = substr($path, 0, $lastSlash);
        }

        return $this->permanentRedirect($this->buildCanonicalUrl($path, $params, [...$context, 'loader' => $loader]));
    }

    private function permanentRedirect(string $url): RedirectResponse
    {
        $response = new RedirectResponse($url, Response::HTTP_MOVED_PERMANENTLY);
        $response->setPublic();
        $response->setMaxAge(self::LEGACY_REDIRECT_MAX_AGE);

        return $response;
    }

    /**
     * Rebuild the current-scheme URL for a legacy request, so it can be redirected.
     *
     * @param array<string, mixed> $params
     * @param TransformerContext   $context
     */
    private function buildCanonicalUrl(string $path, array $params, array $context): string
    {
        return $this->url(
            new Image(path: $path),
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
     * Build the params segment from Glide params (excluding the signature).
     *
     * @param TransformerParams $glideParams
     */
    public function buildParamsSegment(array $glideParams): string
    {
        $filtered = array_filter(
            $glideParams,
            static fn (string $key): bool => 's' !== $key,
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
     * into its component parts. "_untransformed.jpg" carries no params.
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

        $pairs = self::UNTRANSFORMED_PARAMS_SEGMENT === $paramsString ? [] : explode(',', $paramsString);
        $paramPairs = [];

        foreach ($pairs as $pair) {
            $separatorPos = strpos($pair, '_');
            // A pair needs a key: "_300", or the reserved "_untransformed" segment
            // combined with other params, is not a valid params segment
            if (false === $separatorPos || 0 === $separatorPos) {
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
