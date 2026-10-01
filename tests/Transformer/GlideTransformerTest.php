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

use League\Glide\Signatures\SignatureFactory;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlideTransformerTest extends TestCase
{
    private const SIGN_KEY = 'test-secret-key';

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
            new UrlEncryption(self::SIGN_KEY),
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

    public function testUrlIncludesEncryptedMetadata(): void
    {
        $image = new Image(path: 'photo.jpg', metadata: ['upload_destination' => '/var/uploads/images']);
        $transformation = new ImageTransformation(width: 300);

        $url = $this->transformer->url($image, $transformation, ['loader' => 'vich', 'transformer' => 'glide']);

        self::assertStringContainsString('_metadata=', $url);
        self::assertStringContainsString('/picasso/glide/vich/photo.jpg', $url);

        // Extract the _metadata param and verify it decrypts to the original metadata
        $queryString = parse_url($url, \PHP_URL_QUERY);
        self::assertIsString($queryString);
        parse_str($queryString, $query);
        self::assertArrayHasKey('_metadata', $query);
        $encryption = new UrlEncryption(self::SIGN_KEY);
        self::assertIsString($query['_metadata']);
        $decrypted = json_decode($encryption->decrypt($query['_metadata']), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['upload_destination' => '/var/uploads/images'], $decrypted);
    }

    public function testUrlWithMetadataIsStable(): void
    {
        // The same thumb requested twice (twice in a page, or on another request)
        // must get the same URL so browsers and CDNs fetch it only once.
        $image = new Image(path: 'photo.jpg', metadata: ['upload_destination' => '/var/uploads/images']);
        $transformation = new ImageTransformation(width: 300, format: 'webp');
        $context = ['loader' => 'vich', 'transformer' => 'glide'];

        $publicCacheTransformer = new GlideTransformer($this->router, new UrlEncryption(self::SIGN_KEY), self::SIGN_KEY, '/tmp/cache', 'gd', null, true);

        self::assertSame($this->transformer->url($image, $transformation, $context), $this->transformer->url($image, $transformation, $context));
        self::assertSame($publicCacheTransformer->url($image, $transformation, $context), $publicCacheTransformer->url($image, $transformation, $context));
    }

    public function testUrlIsPrefixedWithTheBaseUrl(): void
    {
        $image = new Image(path: 'uploads/photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');
        $context = ['loader' => 'filesystem', 'transformer' => 'glide'];

        foreach ([false, true] as $publicCache) {
            $relative = new GlideTransformer($this->router, new UrlEncryption(self::SIGN_KEY), self::SIGN_KEY, '/tmp/cache', 'gd', null, $publicCache);
            $onCdn = new GlideTransformer($this->router, new UrlEncryption(self::SIGN_KEY), self::SIGN_KEY, '/tmp/cache', 'gd', null, $publicCache, null, 'https://cdn.example.com/');

            self::assertSame('https://cdn.example.com' . $relative->url($image, $transformation, $context), $onCdn->url($image, $transformation, $context));
        }
    }

    public function testComputeCachePathAppliesTheCachePrefix(): void
    {
        $context = ['loader' => 'filesystem', 'transformer' => 'glide'];
        $prefixed = new GlideTransformer($this->router, new UrlEncryption(self::SIGN_KEY), self::SIGN_KEY, '/tmp/cache', 'gd', null, true, null, null, '/image/');

        self::assertSame('glide/filesystem/uploads/photo.jpg/w_300.webp', $this->transformer->computeCachePath('uploads/photo.jpg', 'w_300.webp', $context));
        self::assertSame('image/glide/filesystem/uploads/photo.jpg/w_300.webp', $prefixed->computeCachePath('uploads/photo.jpg', 'w_300.webp', $context));
    }

    public function testUrlOmitsMetadataWhenEmpty(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 300);

        $url = $this->transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        self::assertStringNotContainsString('_metadata=', $url);
    }

    // --- Public cache URL generation ---

    public function testUrlGeneratesPublicCacheUrlWhenEnabled(): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlEncryption(self::SIGN_KEY),
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
        yield 'filename without extension' => ['uploads/photo', 'uploads/photo/_untransformed.'];
    }

    #[DataProvider('untransformedImagePathProvider')]
    public function testPublicCacheUrlNeverHasEmptyParamsSegment(string $path, string $expectedPath): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlEncryption(self::SIGN_KEY),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            true,
        );

        $url = $transformer->url(new Image(path: $path), new ImageTransformation(), ['loader' => 'filesystem', 'transformer' => 'glide']);

        $signature = SignatureFactory::create(self::SIGN_KEY)->generateSignature($expectedPath, []);
        self::assertSame('/picasso/glide/filesystem/' . $expectedPath . '?s=' . $signature, $url);
    }

    public function testPublicCacheUrlPassesMetadataAsQueryParam(): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlEncryption(self::SIGN_KEY),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            true,
        );

        $image = new Image(path: 'photo.jpg', metadata: ['upload_destination' => '/var/uploads']);
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $url = $transformer->url($image, $transformation, ['loader' => 'vich', 'transformer' => 'glide']);

        // metadata should be in query string, not in path
        $parsedUrl = parse_url($url);
        self::assertIsString($parsedUrl['query'] ?? null);
        self::assertStringContainsString('_metadata=', $parsedUrl['query']);
        // Path should only contain transformation params
        self::assertStringContainsString('w_300', $parsedUrl['path'] ?? '');
        self::assertStringNotContainsString('_metadata', $parsedUrl['path'] ?? '');
    }

    public function testPublicCacheUrlParamsAreSorted(): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlEncryption(self::SIGN_KEY),
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
            new UrlEncryption(self::SIGN_KEY),
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

    public function testBuildParamsSegmentExcludesMetadataAndSignature(): void
    {
        $params = ['w' => 300, '_metadata' => 'encrypted_data', 's' => 'signature', 'fm' => 'webp'];

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
            new UrlEncryption(self::SIGN_KEY),
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
        $expectedSignature = SignatureFactory::create(self::SIGN_KEY)
            ->generateSignature($cachedPath, []);
        self::assertSame($expectedSignature, $query['s']);
    }
}
