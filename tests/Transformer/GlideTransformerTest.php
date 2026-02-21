<?php

namespace Silarhi\PicassoBundle\Tests\Transformer;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlideTransformerTest extends TestCase
{
    private const SIGN_KEY = 'test-secret-key';

    private GlideTransformer $transformer;
    private UrlGeneratorInterface $router;

    protected function setUp(): void
    {
        $this->router = $this->createMock(UrlGeneratorInterface::class);
        $this->router->method('generate')
            ->willReturnCallback(function (string $name, array $params): string {
                return '/picasso/'.$params['transformer'].'/'.$params['loader'].'/'.$params['path'].'?'.http_build_query(
                    array_filter($params, fn ($k) => !\in_array($k, ['transformer', 'loader', 'path'], true), ARRAY_FILTER_USE_KEY),
                );
            });

        $this->transformer = new GlideTransformer(
            $this->router,
            self::SIGN_KEY,
            '/tmp/cache',
        );
    }

    public function testUrlGeneratesSignedUrl(): void
    {
        $image = new Image(path: 'uploads/photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $url = $this->transformer->url($image, $transformation, ['loader' => 'filesystem']);

        self::assertStringContainsString('/picasso/glide/filesystem/uploads/photo.jpg', $url);
        self::assertStringContainsString('w=300', $url);
        self::assertStringContainsString('fm=webp', $url);
        self::assertStringContainsString('s=', $url);
    }

    public function testUrlDefaultsLoaderToFilesystem(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 100);

        $url = $this->transformer->url($image, $transformation);

        self::assertStringContainsString('/picasso/glide/filesystem/photo.jpg', $url);
    }

    public function testUrlIncludesQuality(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(quality: 90);

        $url = $this->transformer->url($image, $transformation);

        self::assertStringContainsString('q=90', $url);
    }

    public function testUrlIncludesBlur(): void
    {
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(blur: 50);

        $url = $this->transformer->url($image, $transformation);

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

        $url = $this->transformer->url($image, $transformation);

        self::assertStringContainsString('w=300', $url);
        self::assertStringContainsString('h=200', $url);
        self::assertStringContainsString('fm=avif', $url);
        self::assertStringContainsString('q=85', $url);
        self::assertStringContainsString('fit=crop', $url);
        self::assertStringContainsString('blur=10', $url);
        self::assertStringContainsString('dpr=2', $url);
    }
}
