<?php

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Service\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class UrlGeneratorTest extends TestCase
{
    private UrlGenerator $urlGenerator;

    protected function setUp(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')
            ->willReturnCallback(function (string $name, array $params): string {
                $path = $params['path'];
                unset($params['path']);

                return '/picasso/image/'.$path.'?'.http_build_query($params);
            });

        $this->urlGenerator = new UrlGenerator($router, 'test-secret-key');
    }

    public function testGenerateReturnsUrlWithSignature(): void
    {
        $url = $this->urlGenerator->generate('photo.jpg', ['w' => 300, 'fm' => 'webp']);

        self::assertStringContainsString('/picasso/image/photo.jpg', $url);
        self::assertStringContainsString('w=300', $url);
        self::assertStringContainsString('fm=webp', $url);
        self::assertStringContainsString('s=', $url);
    }

    public function testGenerateWithEmptyParams(): void
    {
        $url = $this->urlGenerator->generate('photo.jpg');

        self::assertStringContainsString('/picasso/image/photo.jpg', $url);
        self::assertStringContainsString('s=', $url);
    }

    public function testGenerateWithDifferentPathsProducesDifferentSignatures(): void
    {
        $url1 = $this->urlGenerator->generate('photo1.jpg', ['w' => 300]);
        $url2 = $this->urlGenerator->generate('photo2.jpg', ['w' => 300]);

        // Extract the signature from each URL
        parse_str(parse_url($url1, \PHP_URL_QUERY) ?? '', $params1);
        parse_str(parse_url($url2, \PHP_URL_QUERY) ?? '', $params2);

        self::assertNotSame($params1['s'], $params2['s']);
    }

    public function testGenerateWithDifferentParamsProducesDifferentSignatures(): void
    {
        $url1 = $this->urlGenerator->generate('photo.jpg', ['w' => 300]);
        $url2 = $this->urlGenerator->generate('photo.jpg', ['w' => 600]);

        parse_str(parse_url($url1, \PHP_URL_QUERY) ?? '', $params1);
        parse_str(parse_url($url2, \PHP_URL_QUERY) ?? '', $params2);

        self::assertNotSame($params1['s'], $params2['s']);
    }
}
