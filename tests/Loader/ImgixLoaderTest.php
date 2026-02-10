<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Loader\ImgixLoader;

class ImgixLoaderTest extends TestCase
{
    public function testGetUrlBasic(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photos/hero.jpg', new ImageParams(
            width: 800,
            height: 600,
            format: 'webp',
            quality: 80,
        ));

        self::assertStringStartsWith('https://my-source.imgix.net/photos/hero.jpg?', $url);
        self::assertStringContainsString('w=800', $url);
        self::assertStringContainsString('h=600', $url);
        self::assertStringContainsString('fm=webp', $url);
        self::assertStringContainsString('q=80', $url);
    }

    public function testGetUrlOmitsNullParams(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams(format: 'jpg'));

        self::assertStringNotContainsString('w=', $url);
        self::assertStringNotContainsString('h=', $url);
        self::assertStringNotContainsString('q=', $url);
        self::assertStringContainsString('fm=jpg', $url);
    }

    public function testGetUrlMapsFitContainToClip(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams(width: 300, fit: 'contain'));

        self::assertStringContainsString('fit=clip', $url);
    }

    public function testGetUrlMapsFitCoverToCrop(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams(width: 300, fit: 'cover'));

        self::assertStringContainsString('fit=crop', $url);
    }

    public function testGetUrlMapsFitCropToCrop(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams(width: 300, fit: 'crop'));

        self::assertStringContainsString('fit=crop', $url);
    }

    public function testGetUrlMapsFitFillToFill(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams(width: 300, fit: 'fill'));

        self::assertStringContainsString('fit=fill', $url);
    }

    public function testGetUrlPassesThroughUnknownFit(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams(width: 300, fit: 'scale'));

        self::assertStringContainsString('fit=scale', $url);
    }

    public function testGetUrlIncludesBlurParam(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams(width: 10, blur: 50));

        self::assertStringContainsString('blur=50', $url);
    }

    public function testGetUrlIncludesDprParam(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams(width: 300, dpr: 2));

        self::assertStringContainsString('dpr=2', $url);
    }

    public function testGetUrlWithSignKey(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net', 'my-secret-token');

        $url = $loader->getUrl('photo.jpg', new ImageParams(width: 300));

        self::assertStringContainsString('s=', $url);
    }

    public function testGetUrlWithoutSignKey(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams(width: 300));

        self::assertStringNotContainsString('s=', $url);
    }

    public function testGetUrlSignatureDiffersForDifferentPaths(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net', 'my-secret');

        $url1 = $loader->getUrl('photo1.jpg', new ImageParams(width: 300));
        $url2 = $loader->getUrl('photo2.jpg', new ImageParams(width: 300));

        self::assertNotSame($this->extractParam($url1, 's'), $this->extractParam($url2, 's'));
    }

    public function testGetUrlSignatureDiffersForDifferentParams(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net', 'my-secret');

        $url1 = $loader->getUrl('photo.jpg', new ImageParams(width: 300));
        $url2 = $loader->getUrl('photo.jpg', new ImageParams(width: 600));

        self::assertNotSame($this->extractParam($url1, 's'), $this->extractParam($url2, 's'));
    }

    public function testGetUrlUsesHttpsByDefault(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url = $loader->getUrl('photo.jpg', new ImageParams());

        self::assertStringStartsWith('https://', $url);
    }

    public function testGetUrlCanUseHttp(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net', null, false);

        $url = $loader->getUrl('photo.jpg', new ImageParams());

        self::assertStringStartsWith('http://', $url);
    }

    public function testGetUrlNormalizesLeadingSlash(): void
    {
        $loader = new ImgixLoader('my-source.imgix.net');

        $url1 = $loader->getUrl('photo.jpg', new ImageParams());
        $url2 = $loader->getUrl('/photo.jpg', new ImageParams());

        self::assertSame($url1, $url2);
    }

    private function extractParam(string $url, string $param): ?string
    {
        parse_str(parse_url($url, \PHP_URL_QUERY) ?? '', $params);

        return $params[$param] ?? null;
    }
}
