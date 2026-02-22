<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Service\LoaderRegistry;

class LoaderRegistryTest extends TestCase
{
    public function testGetReturnsLoader(): void
    {
        $loader = $this->createMock(ImageLoaderInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('filesystem')->willReturn(true);
        $container->method('get')->with('filesystem')->willReturn($loader);

        $registry = new LoaderRegistry($container);

        self::assertSame($loader, $registry->get('filesystem'));
    }

    public function testHasReturnsTrueForKnownLoader(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('filesystem')->willReturn(true);

        $registry = new LoaderRegistry($container);

        self::assertTrue($registry->has('filesystem'));
    }

    public function testHasReturnsFalseForUnknownLoader(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('unknown')->willReturn(false);

        $registry = new LoaderRegistry($container);

        self::assertFalse($registry->has('unknown'));
    }

    public function testGetThrowsForUnknownLoader(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('unknown')->willReturn(false);

        $registry = new LoaderRegistry($container);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Loader "unknown" not found.');
        $registry->get('unknown');
    }
}
