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
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;

class FlysystemLoaderTest extends TestCase
{
    public function testLoadReturnsPathOnly(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $metadataGuesser = $this->createMock(MetadataGuesserInterface::class);
        $loader = new FlysystemLoader($storage, $metadataGuesser);

        $image = $loader->load(new ImageReference('/uploads/photo.jpg'));

        self::assertSame('uploads/photo.jpg', $image->path);
        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testLoadWithMetadataReadsStream(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('readStream')
            ->with('photo.jpg')
            ->willReturn($stream);

        $metadataGuesser = $this->createMock(MetadataGuesserInterface::class);
        $metadataGuesser->expects(self::once())
            ->method('guess')
            ->with($stream)
            ->willReturn(['width' => 640, 'height' => 480, 'mimeType' => 'image/jpeg']);

        $loader = new FlysystemLoader($storage, $metadataGuesser);
        $image = $loader->load(new ImageReference('photo.jpg'), withMetadata: true);

        self::assertSame(640, $image->width);
        self::assertSame(480, $image->height);
        self::assertSame('image/jpeg', $image->mimeType);
    }

    public function testLoadWithMetadataHandlesStreamException(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('readStream')
            ->willThrowException(new RuntimeException('File not found'));

        $metadataGuesser = $this->createMock(MetadataGuesserInterface::class);
        $loader = new FlysystemLoader($storage, $metadataGuesser);

        $image = $loader->load(new ImageReference('missing.jpg'), withMetadata: true);

        self::assertSame('missing.jpg', $image->path);
        self::assertNull($image->width);
    }

    public function testGetSourceReturnsFilesystemOperator(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $metadataGuesser = $this->createMock(MetadataGuesserInterface::class);
        $loader = new FlysystemLoader($storage, $metadataGuesser);
        $source = $loader->getSource();

        self::assertSame($storage, $source);
        self::assertInstanceOf(FilesystemOperator::class, $source);
    }
}
