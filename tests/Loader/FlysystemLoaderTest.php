<?php

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
        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testGetSourceReturnsFilesystemOperator(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $loader = new FlysystemLoader($storage);

        self::assertSame($storage, $loader->getSource());
    }
}
