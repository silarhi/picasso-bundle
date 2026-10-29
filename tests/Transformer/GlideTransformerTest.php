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

namespace Silarhi\PicassoBundle\Tests\Transformer;

use function assert;
use function in_array;
use function is_string;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Silarhi\PicassoBundle\Transformer\GlideSignature;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;

use function strlen;

use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class GlideTransformerTest extends TestCase
{
    private const SIGN_KEY = 'test-secret-key_0123456789abcdef0123456789abcdef';

    private GlideTransformer $transformer;
    private Stub&UrlGeneratorInterface $router;

    protected function setUp(): void
    {
        $this->router = self::createStub(UrlGeneratorInterface::class);
        $this->router->method('generate')
            ->willReturnCallback(static function (string $name, array $params): string {
                assert(is_string($params['transformer']));
                assert(is_string($params['loader']));
                assert(is_string($params['path']));

                $base = '/picasso/' . $params['transformer'] . '/' . $params['loader'] . '/' . $params['path'];

                $extra = array_filter($params, static fn ($k): bool => !in_array($k, ['transformer', 'loader', 'path'], true), \ARRAY_FILTER_USE_KEY);

                if ([] === $extra) {
                    return $base;
                }

                return $base . '?' . http_build_query($extra);
            });

        $this->transformer = new GlideTransformer(
            $this->router,
            new UrlAliases([], []),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            false,
        );
    }

    public function testUrlGeneratesSignedUrl(): void
    {
        $image = new Image(path: 'uploads/photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $url = $this->transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        self::assertStringContainsString('/picasso/glide/filesystem/uploads/photo.jpg', $url);
        self::assertStringContainsString('w=300', $url);
        self::assertStringContainsString('fm=webp', $url);
        self::assertStringContainsString('s=', $url);
    }

    public function testUrlUsesCustomTransformerName(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 100);

        $url = $this->transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'my_glide']);

        self::assertStringContainsString('/picasso/my_glide/filesystem/photo.jpg', $url);
    }

    public function testUrlUsesCustomLoaderName(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 100);

        $url = $this->transformer->url($image, $transformation, ['loader' => 'my_loader', 'transformer' => 'glide']);

        self::assertStringContainsString('/picasso/glide/my_loader/photo.jpg', $url);
    }

    public function testUrlThrowsWhenLoaderMissing(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 100);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('loader');
        $this->transformer->url($image, $transformation, ['transformer' => 'glide']);
    }

    public function testUrlThrowsWhenTransformerMissing(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 100);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('transformer');
        $this->transformer->url($image, $transformation, ['loader' => 'filesystem']);
    }

    public function testUrlThrowsWhenContextEmpty(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 100);

        $this->expectException(LogicException::class);
        $this->transformer->url($image, $transformation);
    }

    public function testUrlIncludesQuality(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(quality: 90);

        $url = $this->transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        self::assertStringContainsString('q=90', $url);
    }

    public function testUrlIncludesBlur(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(blur: 50);

        $url = $this->transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        self::assertStringContainsString('blur=50', $url);
    }

    public function testUrlMapsAllParams(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(
            width: 300,
            height: 200,
            format: 'avif',
            quality: 85,
            fit: 'crop',
            blur: 10,
            dpr: 2,
        );

        $url = $this->transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        self::assertStringContainsString('w=300', $url);
        self::assertStringContainsString('h=200', $url);
        self::assertStringContainsString('fm=avif', $url);
        self::assertStringContainsString('q=85', $url);
        self::assertStringContainsString('fit=crop', $url);
        self::assertStringContainsString('blur=10', $url);
        self::assertStringContainsString('dpr=2', $url);
    }

    public function testUrlIsStable(): void
    {
        // The same thumb requested twice (twice in a page, or on another request)
        // must get the same URL so browsers and CDNs fetch it only once.
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');
        $context = ['loader' => 'vich', 'transformer' => 'glide'];

        $publicCacheTransformer = new GlideTransformer($this->router, new UrlAliases([], []), self::SIGN_KEY, '/tmp/cache', 'gd', null, true);

        self::assertSame($this->transformer->url($image, $transformation, $context), $this->transformer->url($image, $transformation, $context));
        self::assertSame($publicCacheTransformer->url($image, $transformation, $context), $publicCacheTransformer->url($image, $transformation, $context));
    }

    public function testUrlIsPrefixedWithTheBaseUrl(): void
    {
        $image = new Image(path: 'uploads/photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');
        $context = ['loader' => 'filesystem', 'transformer' => 'glide'];

        foreach ([false, true] as $publicCache) {
            $relative = new GlideTransformer($this->router, new UrlAliases([], []), self::SIGN_KEY, '/tmp/cache', 'gd', null, $publicCache);
            $onCdn = new GlideTransformer($this->router, new UrlAliases([], []), self::SIGN_KEY, '/tmp/cache', 'gd', null, $publicCache, null, 'https://cdn.example.com/');

            self::assertSame('https://cdn.example.com' . $relative->url($image, $transformation, $context), $onCdn->url($image, $transformation, $context));
        }
    }

    public function testComputeCachePathAppliesTheCachePrefix(): void
    {
        $context = ['loader' => 'filesystem', 'transformer' => 'glide'];
        $prefixed = new GlideTransformer($this->router, new UrlAliases([], []), self::SIGN_KEY, '/tmp/cache', 'gd', null, true, null, null, '/image/');

        self::assertSame('glide/filesystem/uploads/photo.jpg/w_300.webp', $this->transformer->computeCachePath('uploads/photo.jpg', 'w_300.webp', $context));
        self::assertSame('image/glide/filesystem/uploads/photo.jpg/w_300.webp', $prefixed->computeCachePath('uploads/photo.jpg', 'w_300.webp', $context));
    }

    public function testUrlAndPublicCacheKeyNameTheTransformerAndLoaderByTheirUrlAlias(): void
    {
        $aliases = new UrlAliases(['product_image' => 'p'], ['glide' => 'g']);
        $transformer = new GlideTransformer($this->router, $aliases, self::SIGN_KEY, '/tmp/cache', 'gd', null, true, null, null, 'image');
        $context = ['loader' => 'product_image', 'transformer' => 'glide'];

        $url = $transformer->url(new Image(path: 'photo.jpg'), new ImageTransformation(width: 300, format: 'webp'), $context);

        self::assertStringStartsWith('/picasso/g/p/photo.jpg/fm_webp%2Cw_300.webp?s=', $url);
        // The cache key mirrors the URL path, so a CDN in front of the bucket finds it
        self::assertSame('image/g/p/photo.jpg/fm_webp,w_300.webp', $transformer->computeCachePath('photo.jpg', 'fm_webp,w_300.webp', $context));
    }

    public function testUrlCarriesOnlyTransformationAndSignature(): void
    {
        // The loader name in the path is all serving needs to find the source again
        $url = $this->transformer->url(new Image(path: 'photo.jpg'), new ImageTransformation(width: 300), ['loader' => 'vich', 'transformer' => 'glide']);

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame(['w', 's'], array_keys($query));
    }

    // --- Public cache URL generation ---

    public function testUrlGeneratesPublicCacheUrlWhenEnabled(): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlAliases([], []),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            true,
        );

        $image = new Image(path: 'uploads/photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $url = $transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        // Transformation params are in the path, signature is in query string
        self::assertStringContainsString('/picasso/glide/filesystem/uploads/photo.jpg/', $url);
        self::assertStringContainsString('w_300', $url);
        self::assertStringContainsString('fm_webp', $url);
        self::assertStringContainsString('.webp', $url);
        self::assertStringContainsString('s=', $url);
        // Transformation params should NOT appear as query params
        self::assertStringNotContainsString('w=300', $url);
        self::assertStringNotContainsString('fm=webp', $url);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function untransformedImagePathProvider(): iterable
    {
        yield 'filename with extension' => ['uploads/photo.jpg', 'uploads/photo.jpg/_untransformed.jpg'];
        yield 'filename without extension' => ['uploads/photo', 'uploads/photo/_untransformed.jpg'];
        yield 'uppercase image extension' => ['uploads/PHOTO.JPG', 'uploads/PHOTO.JPG/_untransformed.JPG'];
        yield 'png source' => ['uploads/logo.png', 'uploads/logo.png/_untransformed.png'];
        // The web server would serve these as HTML, or run them
        yield 'html source' => ['uploads/page.html', 'uploads/page.html/_untransformed.jpg'];
        yield 'php source' => ['uploads/shell.php', 'uploads/shell.php/_untransformed.jpg'];
        yield 'svg source' => ['uploads/icon.svg', 'uploads/icon.svg/_untransformed.jpg'];
    }

    #[DataProvider('untransformedImagePathProvider')]
    public function testPublicCacheUrlNeverHasEmptyParamsSegment(string $path, string $expectedPath): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlAliases([], []),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            true,
        );

        $url = $transformer->url(new Image(path: $path), new ImageTransformation(), ['loader' => 'filesystem', 'transformer' => 'glide']);

        $signature = (new GlideSignature(self::SIGN_KEY))->generateSignature($expectedPath, []);
        self::assertSame('/picasso/glide/filesystem/' . $expectedPath . '?s=' . $signature, $url);
    }

    /**
     * @return iterable<string, array{string, ImageTransformation, string}>
     */
    public static function publicCacheExtensionProvider(): iterable
    {
        yield 'format of fm' => ['uploads/page.html', new ImageTransformation(width: 300, format: 'webp'), 'uploads/page.html/fm_webp%2Cw_300.webp'];
        yield 'progressive jpeg is a jpeg' => ['uploads/photo.png', new ImageTransformation(width: 300, format: 'pjpg'), 'uploads/photo.png/fm_pjpg%2Cw_300.jpg'];
        yield 'no fm keeps the source format' => ['uploads/photo.webp', new ImageTransformation(width: 300), 'uploads/photo.webp/w_300.webp'];
        yield 'no fm on a non-image name' => ['uploads/page.html', new ImageTransformation(width: 300), 'uploads/page.html/w_300.jpg'];
        yield 'fm that is not an image format' => ['uploads/photo.jpg', new ImageTransformation(width: 300, format: 'php'), 'uploads/photo.jpg/fm_php%2Cw_300.jpg'];
    }

    #[DataProvider('publicCacheExtensionProvider')]
    public function testPublicCacheUrlEndsWithAnImageExtension(string $path, ImageTransformation $transformation, string $expectedPath): void
    {
        $transformer = new GlideTransformer($this->router, new UrlAliases([], []), self::SIGN_KEY, '/tmp/cache', 'gd', null, true);

        $url = $transformer->url(new Image(path: $path), $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        self::assertSame('/picasso/glide/filesystem/' . $expectedPath, parse_url($url, \PHP_URL_PATH));
    }

    public function testPublicCacheUrlPassesMetadataAsQueryParam(): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlAliases([], []),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            true,
        );

        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $url = $transformer->url($image, $transformation, ['loader' => 'vich', 'transformer' => 'glide']);

        // Transformation params go to the path, only the signature stays in the query string
        $parsedUrl = parse_url($url);
        self::assertStringContainsString('w_300', $parsedUrl['path'] ?? '');
        parse_str($parsedUrl['query'] ?? '', $query);
        self::assertSame(['s'], array_keys($query));
    }

    public function testPublicCacheUrlParamsAreSorted(): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlAliases([], []),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            true,
        );

        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 300, height: 200, format: 'webp', quality: 85, fit: 'crop');

        $url = $transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        // Extract filename from URL path (before query string)
        $urlPath = parse_url($url, \PHP_URL_PATH);
        self::assertIsString($urlPath);
        $filename = basename($urlPath);
        $paramsString = substr($filename, 0, (int) strrpos($filename, '.'));

        // Params should be sorted alphabetically
        $pairs = explode(',', $paramsString);
        $keys = [];
        foreach ($pairs as $pair) {
            $keys[] = substr($pair, 0, (int) strpos($pair, '_'));
        }
        $sorted = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys);
    }

    public function testIsPublicCacheEnabledReturnsFalseByDefault(): void
    {
        self::assertFalse($this->transformer->isPublicCacheEnabled());
    }

    public function testIsPublicCacheEnabledReturnsTrueWhenConfigured(): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlAliases([], []),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            true,
        );

        self::assertTrue($transformer->isPublicCacheEnabled());
    }

    // --- Params segment building and parsing ---

    public function testBuildParamsSegment(): void
    {
        $params = ['w' => 300, 'h' => 200, 'fm' => 'webp', 'q' => 75, 'fit' => 'contain'];

        $segment = $this->transformer->buildParamsSegment($params);

        self::assertSame('fit_contain,fm_webp,h_200,q_75,w_300', $segment);
    }

    public function testBuildParamsSegmentExcludesSignature(): void
    {
        $params = ['w' => 300, 's' => 'signature', 'fm' => 'webp'];

        $segment = $this->transformer->buildParamsSegment($params);

        self::assertSame('fm_webp,w_300', $segment);
    }

    public function testParseParamsFilename(): void
    {
        $filename = 'fit_contain,fm_webp,h_200,q_75,w_300.webp';

        $result = GlideTransformer::parseParamsFilename($filename);

        self::assertSame(['fit' => 'contain', 'fm' => 'webp', 'h' => '200', 'q' => '75', 'w' => '300'], $result['params']);
        self::assertSame('fit_contain,fm_webp,h_200,q_75,w_300', $result['paramsSegment']);
        self::assertSame('webp', $result['format']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function untransformedParamsFilenameProvider(): iterable
    {
        yield 'with extension' => ['_untransformed.jpg', 'jpg'];
        yield 'without extension' => ['_untransformed.', ''];
    }

    #[DataProvider('untransformedParamsFilenameProvider')]
    public function testParseParamsFilenameParsesUntransformedSegment(string $filename, string $expectedFormat): void
    {
        $result = GlideTransformer::parseParamsFilename($filename);

        self::assertSame([], $result['params']);
        self::assertSame('_untransformed', $result['paramsSegment']);
        self::assertSame($expectedFormat, $result['format']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidParamsFilenameProvider(): iterable
    {
        yield 'empty params segment' => ['.jpg'];
        yield 'untransformed segment combined with params' => ['_untransformed,w_300.jpg'];
        yield 'param without key' => ['_300.jpg'];
        yield 'params with a keyless pair' => ['w_300,_80.jpg'];
    }

    #[DataProvider('invalidParamsFilenameProvider')]
    public function testParseParamsFilenameRejectsInvalidSegment(string $filename): void
    {
        $this->expectException(\Silarhi\PicassoBundle\Exception\ImageNotFoundException::class);
        $this->expectExceptionMessage('Invalid cached image param format.');

        GlideTransformer::parseParamsFilename($filename);
    }

    public function testParseParamsFilenameThrowsWithoutExtension(): void
    {
        $this->expectException(\Silarhi\PicassoBundle\Exception\ImageNotFoundException::class);

        GlideTransformer::parseParamsFilename('no-extension');
    }

    public function testParseParamsFilenameThrowsOnPairWithoutSeparator(): void
    {
        $this->expectException(\Silarhi\PicassoBundle\Exception\ImageNotFoundException::class);
        $this->expectExceptionMessage('Invalid cached image param format.');

        GlideTransformer::parseParamsFilename('w_300,webp.webp');
    }

    public function testRoundTripBuildAndParseParams(): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlAliases([], []),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            true,
        );

        $image = new Image(path: 'photos/hero.jpg');
        $transformation = new ImageTransformation(width: 800, height: 600, format: 'avif', quality: 90, fit: 'cover');

        $url = $transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        // Commas in the params segment are percent-encoded so that naive srcset
        // splitters cannot tear the URL apart; decode like the router does.
        self::assertStringNotContainsString(',', $url);

        // Extract filename from URL path
        $urlPath = parse_url($url, \PHP_URL_PATH);
        self::assertIsString($urlPath);
        $filename = rawurldecode(basename($urlPath));
        $parsed = GlideTransformer::parseParamsFilename($filename);

        self::assertSame('800', $parsed['params']['w']);
        self::assertSame('600', $parsed['params']['h']);
        self::assertSame('avif', $parsed['params']['fm']);
        self::assertSame('90', $parsed['params']['q']);
        self::assertSame('cover', $parsed['params']['fit']);
        self::assertSame('avif', $parsed['format']);

        // Verify Glide signature from query string is valid
        $queryString = parse_url($url, \PHP_URL_QUERY);
        self::assertIsString($queryString);
        parse_str($queryString, $query);
        self::assertIsString($query['s']);

        // The signature is computed against the cached path with no extra params
        // (all transformation params are embedded in the path itself)
        $cachedPath = 'photos/hero.jpg/' . $parsed['paramsSegment'] . '.avif';
        $expectedSignature = (new GlideSignature(self::SIGN_KEY))->generateSignature($cachedPath, []);
        self::assertSame($expectedSignature, $query['s']);
    }

    /**
     * The URL templates fill in the signature without the router: a base64url
     * signature ("-", "_") must come out exactly as the router writes it, and
     * validate once parsed back from the URL, in both URL schemes.
     */
    public function testBase64UrlSignaturesSurviveTheRouterAndTheUrlTemplates(): void
    {
        $routes = new RouteCollection();
        $routes->add('picasso_image', new Route('/image/{transformer}/{loader}/{path}', requirements: ['path' => '.+']));
        $router = new UrlGenerator($routes, new RequestContext());
        $signature = new GlideSignature(self::SIGN_KEY);
        $context = ['loader' => 'filesystem', 'transformer' => 'glide'];
        $urlSafeCharacters = '';

        foreach ([false, true] as $publicCache) {
            $transformer = new GlideTransformer($router, new UrlAliases([], []), self::SIGN_KEY, '/tmp/cache', 'gd', null, $publicCache);

            for ($width = 16; $width <= 4096; $width += 16) {
                $url = $transformer->url(new Image(path: 'uploads/photo.jpg'), new ImageTransformation(width: $width, format: 'webp'), $context);
                parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
                self::assertIsString($query['s']);
                self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['s']);
                self::assertStringEndsWith('s=' . $query['s'], $url, 'The signature must reach the URL unencoded.');

                $path = rawurldecode(substr((string) parse_url($url, \PHP_URL_PATH), strlen('/image/glide/filesystem/')));
                $signature->validateRequest($path, $query);

                if (!$publicCache) {
                    // What the router itself writes, which the templates must reproduce
                    self::assertSame($router->generate('picasso_image', ['transformer' => 'glide', 'loader' => 'filesystem', 'path' => 'uploads/photo.jpg', 'w' => $width, 'fm' => 'webp', 's' => $query['s']]), $url);
                }

                $urlSafeCharacters .= preg_replace('/[A-Za-z0-9]/', '', $query['s']);
            }
        }

        self::assertStringContainsString('-', $urlSafeCharacters);
        self::assertStringContainsString('_', $urlSafeCharacters);
    }

    public function testSignKeysStayOutOfStackTraces(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            new GlideTransformer($this->router, new UrlAliases([], []), [self::SIGN_KEY, ''], '/tmp/cache', 'gd', null, false);
            self::fail('An empty key must be rejected.');
        } catch (InvalidConfigurationException $e) {
            $trace = $e->getTraceAsString();
            self::assertStringContainsString('GlideTransformer->__construct(', $trace);
            self::assertStringContainsString('SensitiveParameterValue', $trace);
            self::assertStringNotContainsString(substr(self::SIGN_KEY, 0, 10), $trace);
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '');
        }
    }
}
