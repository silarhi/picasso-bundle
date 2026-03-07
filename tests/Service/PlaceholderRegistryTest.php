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
use Silarhi\PicassoBundle\Exception\PlaceholderNotFoundException;
use Silarhi\PicassoBundle\Placeholder\PlaceholderInterface;
use Silarhi\PicassoBundle\Service\PlaceholderRegistry;

class PlaceholderRegistryTest extends TestCase
{
    public function testGetReturnsPlaceholder(): void
    {
        $placeholder = $this->createMock(PlaceholderInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('blur')->willReturn(true);
        $container->method('get')->with('blur')->willReturn($placeholder);

        $registry = new PlaceholderRegistry($container);

        self::assertSame($placeholder, $registry->get('blur'));
    }

    public function testHasReturnsTrueForKnownPlaceholder(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('blur')->willReturn(true);

        $registry = new PlaceholderRegistry($container);

        self::assertTrue($registry->has('blur'));
    }

    public function testHasReturnsFalseForUnknownPlaceholder(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('unknown')->willReturn(false);

        $registry = new PlaceholderRegistry($container);

        self::assertFalse($registry->has('unknown'));
    }

    public function testGetThrowsForUnknownPlaceholder(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('unknown')->willReturn(false);

        $registry = new PlaceholderRegistry($container);

        $this->expectException(PlaceholderNotFoundException::class);
        $this->expectExceptionMessage('Placeholder "unknown" not found.');
        $registry->get('unknown');
    }
}
