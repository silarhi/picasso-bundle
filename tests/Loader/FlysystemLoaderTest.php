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

use Closure;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;

class FlysystemLoaderTest extends TestCase
{
    public function testLoadReturnsPathWithLazyStream(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $loader = new FlysystemLoader($storage);

        $image = $loader->load(new ImageReference('/uploads/photo.jpg'));

        self::assertSame('uploads/photo.jpg', $image->path);
        self::assertInstanceOf(Closure::class, $image->stream);
        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testLazyStreamResolvesToResource(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('readStream')
            ->with('photo.jpg')
            ->willReturn($stream);

        $loader = new FlysystemLoader($storage);
        $image = $loader->load(new ImageReference('photo.jpg'));

        self::assertInstanceOf(Closure::class, $image->stream);
        self::assertSame($stream, ($image->stream)());
    }

    public function testLazyStreamReturnsNullOnException(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('readStream')
            ->willThrowException(new RuntimeException('File not found'));

        $loader = new FlysystemLoader($storage);
        $image = $loader->load(new ImageReference('missing.jpg'));

        self::assertInstanceOf(Closure::class, $image->stream);
        self::assertNull($image->resolveStream());
    }

    public function testLoadEmptyPathHasNullStream(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $loader = new FlysystemLoader($storage);

        $image = $loader->load(new ImageReference());

        self::assertNull($image->path);
        self::assertNull($image->stream);
    }

    public function testGetSourceReturnsFilesystemOperator(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $loader = new FlysystemLoader($storage);
        $source = $loader->getSource([]);

        self::assertSame($storage, $source);
        self::assertInstanceOf(FilesystemOperator::class, $source);
    }
}
