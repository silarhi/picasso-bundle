<?php

namespace Silarhi\PicassoBundle\Tests\Service;

use League\Glide\Server;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\BlurPlaceholderConfig;
use Silarhi\PicassoBundle\Service\GlideBlurHashGenerator;

class GlideBlurHashGeneratorTest extends TestCase
{
    public function testGenerateReturnsNullWhenDisabled(): void
    {
        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('makeImage');

        $generator = new GlideBlurHashGenerator(
            $server,
            new BlurPlaceholderConfig(enabled: false),
        );

        self::assertNull($generator->generate('photo.jpg'));
    }

    public function testIsEnabledReturnsConfigValue(): void
    {
        $server = $this->createMock(Server::class);

        $enabled = new GlideBlurHashGenerator(
            $server,
            new BlurPlaceholderConfig(enabled: true),
        );

        $disabled = new GlideBlurHashGenerator(
            $server,
            new BlurPlaceholderConfig(enabled: false),
        );

        self::assertTrue($enabled->isEnabled());
        self::assertFalse($disabled->isEnabled());
    }

    public function testGenerateReturnsDataUri(): void
    {
        $fakeImageContent = 'fake-jpeg-data';

        $cache = $this->createMock(\League\Flysystem\FilesystemOperator::class);
        $cache->method('read')->willReturn($fakeImageContent);

        $server = $this->createMock(Server::class);
        $server->method('makeImage')->willReturn('cached/photo.jpg');
        $server->method('getCache')->willReturn($cache);

        $generator = new GlideBlurHashGenerator(
            $server,
            new BlurPlaceholderConfig(enabled: true, size: 10, blur: 50, quality: 30),
        );

        $result = $generator->generate('photo.jpg', 1920, 1080);

        self::assertStringStartsWith('data:image/jpeg;base64,', $result);
        self::assertSame(
            'data:image/jpeg;base64,'.base64_encode($fakeImageContent),
            $result,
        );
    }

    public function testGenerateCallsMakeImageWithCorrectAspectRatio(): void
    {
        $cache = $this->createMock(\League\Flysystem\FilesystemOperator::class);
        $cache->method('read')->willReturn('data');

        $server = $this->createMock(Server::class);
        $server->expects(self::once())
            ->method('makeImage')
            ->with('photo.jpg', self::callback(function (array $params): bool {
                // 1920x1080 -> 10x6 (rounded)
                return $params['w'] === 10
                    && $params['h'] === 6
                    && $params['blur'] === 50
                    && $params['q'] === 30
                    && $params['fm'] === 'jpg';
            }))
            ->willReturn('cached/photo.jpg');
        $server->method('getCache')->willReturn($cache);

        $generator = new GlideBlurHashGenerator(
            $server,
            new BlurPlaceholderConfig(enabled: true, size: 10, blur: 50, quality: 30),
        );

        $generator->generate('photo.jpg', 1920, 1080);
    }

    public function testGenerateUsesSquareWhenNoDimensions(): void
    {
        $cache = $this->createMock(\League\Flysystem\FilesystemOperator::class);
        $cache->method('read')->willReturn('data');

        $server = $this->createMock(Server::class);
        $server->expects(self::once())
            ->method('makeImage')
            ->with('photo.jpg', self::callback(function (array $params): bool {
                return $params['w'] === 10 && $params['h'] === 10;
            }))
            ->willReturn('cached/photo.jpg');
        $server->method('getCache')->willReturn($cache);

        $generator = new GlideBlurHashGenerator(
            $server,
            new BlurPlaceholderConfig(enabled: true, size: 10, blur: 50, quality: 30),
        );

        $generator->generate('photo.jpg');
    }

    public function testGenerateReturnsNullOnException(): void
    {
        $server = $this->createMock(Server::class);
        $server->method('makeImage')
            ->willThrowException(new \RuntimeException('File not found'));

        $generator = new GlideBlurHashGenerator(
            $server,
            new BlurPlaceholderConfig(enabled: true, size: 10, blur: 50, quality: 30),
        );

        self::assertNull($generator->generate('missing.jpg'));
    }
}
