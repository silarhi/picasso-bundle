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

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Service\LoaderRegistry;

class LoaderRegistryTest extends TestCase
{
    public function testGetReturnsLoader(): void
    {
        $loader = $this->createMock(ImageLoaderInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::any())->method('has')->with('filesystem')->willReturn(true);
        $container->expects(self::any())->method('get')->with('filesystem')->willReturn($loader);

        $registry = new LoaderRegistry($container);

        self::assertSame($loader, $registry->get('filesystem'));
    }

    public function testHasReturnsTrueForKnownLoader(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::any())->method('has')->with('filesystem')->willReturn(true);

        $registry = new LoaderRegistry($container);

        self::assertTrue($registry->has('filesystem'));
    }

    public function testHasReturnsFalseForUnknownLoader(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::any())->method('has')->with('unknown')->willReturn(false);

        $registry = new LoaderRegistry($container);

        self::assertFalse($registry->has('unknown'));
    }

    public function testGetThrowsForUnknownLoader(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::any())->method('has')->with('unknown')->willReturn(false);

        $registry = new LoaderRegistry($container);

        $this->expectException(LoaderNotFoundException::class);
        $this->expectExceptionMessage('Loader "unknown" not found.');
        $registry->get('unknown');
    }

    public function testGetResolveMetadataReturnsConfiguredValue(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $registry = new LoaderRegistry($container, [], [], ['filesystem' => true, 'url' => false]);

        self::assertTrue($registry->getResolveMetadata('filesystem'));
        self::assertFalse($registry->getResolveMetadata('url'));
    }

    public function testGetResolveMetadataReturnsNullWhenNotConfigured(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $registry = new LoaderRegistry($container);

        self::assertNull($registry->getResolveMetadata('filesystem'));
    }
}
