<?php

namespace Silarhi\PicassoBundle\Tests\Url;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Url\ImgixImageUrlGenerator;

class ImgixImageUrlGeneratorTest extends TestCase
{
    public function testGenerateBasicUrl(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photos/hero.jpg', new ImageParams(
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

    public function testGenerateOmitsNullParams(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams(format: 'jpg'));

        self::assertStringNotContainsString('w=', $url);
        self::assertStringNotContainsString('h=', $url);
        self::assertStringNotContainsString('q=', $url);
        self::assertStringContainsString('fm=jpg', $url);
    }

    public function testGenerateMapsFitContainToClip(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams(
            width: 300,
            fit: 'contain',
        ));

        self::assertStringContainsString('fit=clip', $url);
    }

    public function testGenerateMapsFitCoverToCrop(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams(
            width: 300,
            fit: 'cover',
        ));

        self::assertStringContainsString('fit=crop', $url);
    }

    public function testGenerateMapsFitCropToCrop(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams(
            width: 300,
            fit: 'crop',
        ));

        self::assertStringContainsString('fit=crop', $url);
    }

    public function testGenerateMapsFitFillToFill(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams(
            width: 300,
            fit: 'fill',
        ));

        self::assertStringContainsString('fit=fill', $url);
    }

    public function testGeneratePassesThroughUnknownFit(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams(
            width: 300,
            fit: 'scale',
        ));

        self::assertStringContainsString('fit=scale', $url);
    }

    public function testGenerateIncludesBlurParam(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams(
            width: 10,
            blur: 50,
        ));

        self::assertStringContainsString('blur=50', $url);
    }

    public function testGenerateIncludesDprParam(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams(
            width: 300,
            dpr: 2,
        ));

        self::assertStringContainsString('dpr=2', $url);
    }

    public function testGenerateWithSignKey(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net', 'my-secret-token');

        $url = $generator->generate('photo.jpg', new ImageParams(width: 300));

        self::assertStringContainsString('s=', $url);
    }

    public function testGenerateWithoutSignKey(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams(width: 300));

        self::assertStringNotContainsString('s=', $url);
    }

    public function testGenerateSignatureDiffersForDifferentPaths(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net', 'my-secret');

        $url1 = $generator->generate('photo1.jpg', new ImageParams(width: 300));
        $url2 = $generator->generate('photo2.jpg', new ImageParams(width: 300));

        $sig1 = $this->extractParam($url1, 's');
        $sig2 = $this->extractParam($url2, 's');

        self::assertNotSame($sig1, $sig2);
    }

    public function testGenerateSignatureDiffersForDifferentParams(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net', 'my-secret');

        $url1 = $generator->generate('photo.jpg', new ImageParams(width: 300));
        $url2 = $generator->generate('photo.jpg', new ImageParams(width: 600));

        $sig1 = $this->extractParam($url1, 's');
        $sig2 = $this->extractParam($url2, 's');

        self::assertNotSame($sig1, $sig2);
    }

    public function testGenerateUsesHttpsByDefault(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url = $generator->generate('photo.jpg', new ImageParams());

        self::assertStringStartsWith('https://', $url);
    }

    public function testGenerateCanUseHttp(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net', null, false);

        $url = $generator->generate('photo.jpg', new ImageParams());

        self::assertStringStartsWith('http://', $url);
    }

    public function testGenerateNormalizesLeadingSlash(): void
    {
        $generator = new ImgixImageUrlGenerator('my-source.imgix.net');

        $url1 = $generator->generate('photo.jpg', new ImageParams());
        $url2 = $generator->generate('/photo.jpg', new ImageParams());

        self::assertSame($url1, $url2);
    }

    private function extractParam(string $url, string $param): ?string
    {
        parse_str(parse_url($url, \PHP_URL_QUERY) ?? '', $params);

        return $params[$param] ?? null;
    }
}
