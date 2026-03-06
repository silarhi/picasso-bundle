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

namespace Silarhi\PicassoBundle\Tests\Loader;

use League\Flysystem\FilesystemOperator;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;

class FlysystemRegistryTest extends TestCase
{
    public function testGetReturnsStorage(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('default')->willReturn(true);
        $container->method('get')->with('default')->willReturn($storage);

        $registry = new FlysystemRegistry($container);

        self::assertSame($storage, $registry->get('default'));
    }

    public function testGetThrowsForUnknownStorage(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('unknown')->willReturn(false);

        $registry = new FlysystemRegistry($container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Flysystem storage "unknown" is not registered.');
        $registry->get('unknown');
    }

    public function testHasReturnsTrueForKnownStorage(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('default')->willReturn(true);

        $registry = new FlysystemRegistry($container);

        self::assertTrue($registry->has('default'));
    }

    public function testHasReturnsFalseForUnknownStorage(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('unknown')->willReturn(false);

        $registry = new FlysystemRegistry($container);

        self::assertFalse($registry->has('unknown'));
    }
}
