<?php

namespace Silarhi\PicassoBundle\Tests\Url;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Url\GlideImageUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlideImageUrlGeneratorTest extends TestCase
{
    private GlideImageUrlGenerator $generator;

    protected function setUp(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')
            ->willReturnCallback(function (string $name, array $params): string {
                $path = $params['path'];
                unset($params['path']);

                return '/picasso/image/'.$path.'?'.http_build_query($params);
            });

        $this->generator = new GlideImageUrlGenerator($router, 'test-secret-key');
    }

    public function testGenerateMapsParamsToGlideFormat(): void
    {
        $url = $this->generator->generate('photo.jpg', new ImageParams(
            width: 300,
            height: 200,
            format: 'webp',
            quality: 80,
            fit: 'crop',
        ));

        self::assertStringContainsString('/picasso/image/photo.jpg', $url);
        self::assertStringContainsString('w=300', $url);
        self::assertStringContainsString('h=200', $url);
        self::assertStringContainsString('fm=webp', $url);
        self::assertStringContainsString('q=80', $url);
        self::assertStringContainsString('fit=crop', $url);
        self::assertStringContainsString('s=', $url);
    }

    public function testGenerateOmitsNullParams(): void
    {
        $url = $this->generator->generate('photo.jpg', new ImageParams(
            format: 'jpg',
        ));

        self::assertStringNotContainsString('w=', $url);
        self::assertStringNotContainsString('h=', $url);
        self::assertStringNotContainsString('q=', $url);
        self::assertStringContainsString('fm=jpg', $url);
    }

    public function testGenerateIncludesBlurParam(): void
    {
        $url = $this->generator->generate('photo.jpg', new ImageParams(
            width: 10,
            blur: 50,
        ));

        self::assertStringContainsString('blur=50', $url);
    }

    public function testGenerateIncludesDprParam(): void
    {
        $url = $this->generator->generate('photo.jpg', new ImageParams(
            width: 300,
            dpr: 2,
        ));

        self::assertStringContainsString('dpr=2', $url);
    }

    public function testGenerateWithDifferentPathsProducesDifferentSignatures(): void
    {
        $params = new ImageParams(width: 300);
        $url1 = $this->generator->generate('photo1.jpg', $params);
        $url2 = $this->generator->generate('photo2.jpg', $params);

        parse_str(parse_url($url1, \PHP_URL_QUERY) ?? '', $params1);
        parse_str(parse_url($url2, \PHP_URL_QUERY) ?? '', $params2);

        self::assertNotSame($params1['s'], $params2['s']);
    }

    public function testGenerateWithDifferentParamsProducesDifferentSignatures(): void
    {
        $url1 = $this->generator->generate('photo.jpg', new ImageParams(width: 300));
        $url2 = $this->generator->generate('photo.jpg', new ImageParams(width: 600));

        parse_str(parse_url($url1, \PHP_URL_QUERY) ?? '', $params1);
        parse_str(parse_url($url2, \PHP_URL_QUERY) ?? '', $params2);

        self::assertNotSame($params1['s'], $params2['s']);
    }
}
