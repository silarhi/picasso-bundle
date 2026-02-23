<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

class TransformerRegistryTest extends TestCase
{
    public function testGetReturnsTransformer(): void
    {
        $transformer = $this->createMock(ImageTransformerInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('glide')->willReturn(true);
        $container->method('get')->with('glide')->willReturn($transformer);

        $registry = new TransformerRegistry($container);

        self::assertSame($transformer, $registry->get('glide'));
    }

    public function testHasReturnsTrueForKnownTransformer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('glide')->willReturn(true);

        $registry = new TransformerRegistry($container);

        self::assertTrue($registry->has('glide'));
    }

    public function testHasReturnsFalseForUnknownTransformer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('unknown')->willReturn(false);

        $registry = new TransformerRegistry($container);

        self::assertFalse($registry->has('unknown'));
    }

    public function testGetThrowsForUnknownTransformer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('unknown')->willReturn(false);

        $registry = new TransformerRegistry($container);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Transformer "unknown" not found.');
        $registry->get('unknown');
    }
}
