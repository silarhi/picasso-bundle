<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Loader;

use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;

class FlysystemLoaderTest extends TestCase
{
    public function testLoadReturnsPathOnly(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $loader = new FlysystemLoader($storage);

        $image = $loader->load(new ImageReference('/uploads/photo.jpg'));

        self::assertSame('uploads/photo.jpg', $image->path);
        self::assertNull($image->stream);
    }

    public function testGetSourceReturnsFilesystemOperator(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $loader = new FlysystemLoader($storage);
        $source = $loader->getSource();

        self::assertSame($storage, $source);
        self::assertInstanceOf(FilesystemOperator::class, $source);
    }
}
