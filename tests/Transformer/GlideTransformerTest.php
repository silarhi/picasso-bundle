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

                return '/picasso/' . $params['transformer'] . '/' . $params['loader'] . '/' . $params['path'] . '?' . http_build_query(
                    array_filter($params, static fn ($k): bool => !in_array($k, ['transformer', 'loader', 'path'], true), \ARRAY_FILTER_USE_KEY),
                );
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
}
