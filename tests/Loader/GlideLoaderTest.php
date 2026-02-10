<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Loader\GlideLoader;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlideLoaderTest extends TestCase
{
    private GlideLoader $loader;

    protected function setUp(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')
            ->willReturnCallback(function (string $name, array $params): string {
                $path = $params['path'];
                unset($params['path']);

                return '/picasso/image/'.$path.'?'.http_build_query($params);
            });

        $this->loader = new GlideLoader($router, 'test-secret-key');
    }

    public function testGetUrlMapsParamsToGlideFormat(): void
    {
        $url = $this->loader->getUrl('photo.jpg', new ImageParams(
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

    public function testGetUrlOmitsNullParams(): void
    {
        $url = $this->loader->getUrl('photo.jpg', new ImageParams(
            format: 'jpg',
        ));

        self::assertStringNotContainsString('w=', $url);
        self::assertStringNotContainsString('h=', $url);
        self::assertStringNotContainsString('q=', $url);
        self::assertStringContainsString('fm=jpg', $url);
    }

    public function testGetUrlIncludesBlurParam(): void
    {
        $url = $this->loader->getUrl('photo.jpg', new ImageParams(
            width: 10,
            blur: 50,
        ));

        self::assertStringContainsString('blur=50', $url);
    }

    public function testGetUrlIncludesDprParam(): void
    {
        $url = $this->loader->getUrl('photo.jpg', new ImageParams(
            width: 300,
            dpr: 2,
        ));

        self::assertStringContainsString('dpr=2', $url);
    }

    public function testGetUrlWithDifferentPathsProducesDifferentSignatures(): void
    {
        $params = new ImageParams(width: 300);
        $url1 = $this->loader->getUrl('photo1.jpg', $params);
        $url2 = $this->loader->getUrl('photo2.jpg', $params);

        parse_str(parse_url($url1, \PHP_URL_QUERY) ?? '', $params1);
        parse_str(parse_url($url2, \PHP_URL_QUERY) ?? '', $params2);

        self::assertNotSame($params1['s'], $params2['s']);
    }

    public function testGetUrlWithDifferentParamsProducesDifferentSignatures(): void
    {
        $url1 = $this->loader->getUrl('photo.jpg', new ImageParams(width: 300));
        $url2 = $this->loader->getUrl('photo.jpg', new ImageParams(width: 600));

        parse_str(parse_url($url1, \PHP_URL_QUERY) ?? '', $params1);
        parse_str(parse_url($url2, \PHP_URL_QUERY) ?? '', $params2);

        self::assertNotSame($params1['s'], $params2['s']);
    }
}
