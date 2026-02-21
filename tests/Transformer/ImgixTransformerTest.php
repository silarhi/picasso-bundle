<?php

namespace Silarhi\PicassoBundle\Tests\Transformer;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Transformer\ImgixTransformer;

class ImgixTransformerTest extends TestCase
{
    public function testUrlGeneratesBasicUrl(): void
    {
        $transformer = new ImgixTransformer('my-source.imgix.net');
        $image = new Image(path: 'photos/photo.jpg');
        $transformation = new ImageTransformation(width: 300, format: 'webp');

        $url = $transformer->url($image, $transformation);

        self::assertStringStartsWith('https://my-source.imgix.net/photos/photo.jpg?', $url);
        self::assertStringContainsString('w=300', $url);
        self::assertStringContainsString('fm=webp', $url);
    }

    public function testUrlGeneratesSignedUrl(): void
    {
        $transformer = new ImgixTransformer('my-source.imgix.net', 'my-sign-key');
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(width: 300);

        $url = $transformer->url($image, $transformation);

        self::assertStringContainsString('s=', $url);
    }

    public function testUrlUsesHttpWhenConfigured(): void
    {
        $transformer = new ImgixTransformer('my-source.imgix.net', null, false);
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation();

        $url = $transformer->url($image, $transformation);

        self::assertStringStartsWith('http://', $url);
    }

    public function testUrlMapsContainFitToClip(): void
    {
        $transformer = new ImgixTransformer('cdn.imgix.net');
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(fit: 'contain');

        $url = $transformer->url($image, $transformation);

        self::assertStringContainsString('fit=clip', $url);
    }

    public function testUrlMapsCoverFitToCrop(): void
    {
        $transformer = new ImgixTransformer('cdn.imgix.net');
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(fit: 'cover');

        $url = $transformer->url($image, $transformation);

        self::assertStringContainsString('fit=crop', $url);
    }

    public function testUrlMapsAllParams(): void
    {
        $transformer = new ImgixTransformer('cdn.imgix.net');
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation(
            width: 300,
            height: 200,
            format: 'avif',
            quality: 85,
            fit: 'fill',
            blur: 10,
            dpr: 2,
        );

        $url = $transformer->url($image, $transformation);

        self::assertStringContainsString('w=300', $url);
        self::assertStringContainsString('h=200', $url);
        self::assertStringContainsString('fm=avif', $url);
        self::assertStringContainsString('q=85', $url);
        self::assertStringContainsString('fit=fill', $url);
        self::assertStringContainsString('blur=10', $url);
        self::assertStringContainsString('dpr=2', $url);
    }

    public function testUrlStripsLeadingSlash(): void
    {
        $transformer = new ImgixTransformer('cdn.imgix.net');
        $image = new Image(path: 'photo.jpg');
        $transformation = new ImageTransformation();

        $url = $transformer->url($image, $transformation);

        self::assertStringStartsWith('https://cdn.imgix.net/photo.jpg', $url);
    }
}
