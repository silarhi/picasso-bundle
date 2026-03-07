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
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;

use function strlen;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlideTransformerTest extends TestCase
{
    private const SIGN_KEY = 'test-secret-key';

    private GlideTransformer $transformer;
    private \PHPUnit\Framework\MockObject\MockObject&UrlGeneratorInterface $router;

    protected function setUp(): void
    {
        $this->router = $this->createMock(UrlGeneratorInterface::class);
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
            ['enabled' => true, 'path' => '/public/cache/picasso'],
        );

        $image = new Image(path: 'uploads/photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $url = $transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        self::assertStringStartsWith('/picasso/glide/filesystem/uploads/photo.jpg/', $url);
        self::assertStringContainsString('w_300', $url);
        self::assertStringContainsString('fm_webp', $url);
        self::assertStringContainsString(',s_', $url);
        self::assertStringEndsWith('.webp', $url);
        // Should NOT contain query params
        self::assertStringNotContainsString('?', $url);
    }

    public function testPublicCacheUrlExcludesMetadataFromPath(): void
    {
        $transformer = new GlideTransformer(
            $this->router,
            new UrlEncryption(self::SIGN_KEY),
            self::SIGN_KEY,
            '/tmp/cache',
            'gd',
            null,
            ['enabled' => true, 'path' => '/public/cache/picasso'],
        );

        $image = new Image(path: 'photo.jpg', metadata: ['upload_destination' => '/var/uploads']);
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $url = $transformer->url($image, $transformation, ['loader' => 'vich', 'transformer' => 'glide']);

        self::assertStringNotContainsString('_metadata', $url);
        self::assertStringContainsString('w_300', $url);
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
            ['enabled' => true, 'path' => '/public/cache/picasso'],
        );

        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 300, height: 200, format: 'webp', quality: 85, fit: 'crop');

        $url = $transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        // Extract filename from URL
        $filename = basename($url);
        $paramsString = substr($filename, 0, (int) strrpos($filename, '.'));

        // Params should be sorted alphabetically
        $pairs = explode(',', $paramsString);
        $keys = [];
        foreach ($pairs as $pair) {
            $keys[] = substr($pair, 0, (int) strpos($pair, '_'));
        }
        // Remove 's' (hmac) for sorting check
        $paramKeys = array_filter($keys, static fn (string $k): bool => 's' !== $k);
        $sorted = $paramKeys;
        sort($sorted);
        self::assertSame($sorted, array_values($paramKeys));
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
            ['enabled' => true, 'path' => '/public/cache/picasso'],
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

    public function testBuildHmacIsDeterministic(): void
    {
        $segment = 'fit_contain,fm_webp,q_75,w_300';

        $hmac1 = $this->transformer->buildHmac($segment);
        $hmac2 = $this->transformer->buildHmac($segment);

        self::assertSame($hmac1, $hmac2);
        self::assertSame(10, strlen($hmac1));
    }

    public function testParseParamsFilename(): void
    {
        $filename = 'fit_contain,fm_webp,h_200,q_75,w_300,s_abc1234567.webp';

        $result = GlideTransformer::parseParamsFilename($filename);

        self::assertSame(['fit' => 'contain', 'fm' => 'webp', 'h' => '200', 'q' => '75', 'w' => '300'], $result['params']);
        self::assertSame('fit_contain,fm_webp,h_200,q_75,w_300', $result['paramsSegment']);
        self::assertSame('abc1234567', $result['hmac']);
        self::assertSame('webp', $result['format']);
    }

    public function testParseParamsFilenameThrowsWithoutExtension(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        GlideTransformer::parseParamsFilename('no-extension');
    }

    public function testParseParamsFilenameThrowsWithoutHmac(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        GlideTransformer::parseParamsFilename('w_300,fm_webp.webp');
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
            ['enabled' => true, 'path' => '/public/cache/picasso'],
        );

        $image = new Image(path: 'photos/hero.jpg');
        $transformation = new ImageTransformation(width: 800, height: 600, format: 'avif', quality: 90, fit: 'cover');

        $url = $transformer->url($image, $transformation, ['loader' => 'filesystem', 'transformer' => 'glide']);

        // Extract filename
        $filename = basename($url);
        $parsed = GlideTransformer::parseParamsFilename($filename);

        self::assertSame('800', $parsed['params']['w']);
        self::assertSame('600', $parsed['params']['h']);
        self::assertSame('avif', $parsed['params']['fm']);
        self::assertSame('90', $parsed['params']['q']);
        self::assertSame('cover', $parsed['params']['fit']);
        self::assertSame('avif', $parsed['format']);

        // Verify HMAC is valid
        $expectedHmac = $transformer->buildHmac($parsed['paramsSegment']);
        self::assertSame($expectedHmac, $parsed['hmac']);
    }
}
