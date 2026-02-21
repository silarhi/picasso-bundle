<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\FilesystemLoader;

class FilesystemLoaderTest extends TestCase
{
    public function testLoadStripsLeadingSlash(): void
    {
        $loader = new FilesystemLoader('/tmp/nonexistent');
        $image = $loader->load(new ImageReference('/uploads/photo.jpg'));

        self::assertSame('uploads/photo.jpg', $image->path);
    }

    public function testLoadWithoutMetadata(): void
    {
        $loader = new FilesystemLoader('/tmp/nonexistent');
        $image = $loader->load(new ImageReference('photo.jpg'), withMetadata: false);

        self::assertSame('photo.jpg', $image->path);
        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->mimeType);
    }

    public function testLoadNonExistentFile(): void
    {
        $loader = new FilesystemLoader('/tmp/nonexistent');
        $image = $loader->load(new ImageReference('missing.jpg'));

        self::assertSame('missing.jpg', $image->path);
        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testGetSourceReturnsBaseDirectory(): void
    {
        $loader = new FilesystemLoader('/var/www/uploads');

        self::assertSame('/var/www/uploads', $loader->getSource());
    }

    public function testLoadWithNullPath(): void
    {
        $loader = new FilesystemLoader('/tmp');
        $image = $loader->load(new ImageReference());

        self::assertSame('', $image->path);
    }
}
