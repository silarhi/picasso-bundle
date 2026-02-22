<?php

declare(strict_types=1);

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

    public function testLoadNonExistentFileHasNullStream(): void
    {
        $loader = new FilesystemLoader('/tmp/nonexistent');
        $image = $loader->load(new ImageReference('missing.jpg'));

        self::assertSame('missing.jpg', $image->path);
        self::assertNull($image->stream);
    }

    public function testGetSourceReturnsString(): void
    {
        $loader = new FilesystemLoader('/var/www/uploads');
        $source = $loader->getSource();

        self::assertSame('/var/www/uploads', $source);
    }

    public function testLoadWithNullPath(): void
    {
        $loader = new FilesystemLoader('/tmp');
        $image = $loader->load(new ImageReference());

        self::assertSame('', $image->path);
    }

    public function testLoadExistingFileHasStream(): void
    {
        $tmpDir = sys_get_temp_dir().'/picasso_test_'.uniqid();
        mkdir($tmpDir, 0o777, true);
        file_put_contents($tmpDir.'/test.txt', 'hello');

        try {
            $loader = new FilesystemLoader($tmpDir);
            $image = $loader->load(new ImageReference('test.txt'));

            self::assertSame('test.txt', $image->path);
            self::assertNotNull($image->stream);
            self::assertIsResource($image->stream);
        } finally {
            @unlink($tmpDir.'/test.txt');
            @rmdir($tmpDir);
        }
    }
}
