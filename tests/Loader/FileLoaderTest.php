<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Loader\FileLoader;

class FileLoaderTest extends TestCase
{
    private FileLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/picasso_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->loader = new FileLoader($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testResolvePathStripsLeadingSlash(): void
    {
        self::assertSame('uploads/photo.jpg', $this->loader->resolvePath('/uploads/photo.jpg'));
    }

    public function testResolvePathReturnsAsIs(): void
    {
        self::assertSame('photo.jpg', $this->loader->resolvePath('photo.jpg'));
    }

    public function testGetDimensionsReturnsNullForNonExistentFile(): void
    {
        self::assertNull($this->loader->getDimensions('nonexistent.jpg'));
    }

    public function testGetDimensionsReturnsNullForNonImageFile(): void
    {
        file_put_contents($this->tempDir.'/file.txt', 'not an image');

        self::assertNull($this->loader->getDimensions('file.txt'));
    }

    public function testGetDimensionsReturnsWidthAndHeight(): void
    {
        // Create a 50x30 PNG image
        $img = imagecreatetruecolor(50, 30);
        imagepng($img, $this->tempDir.'/test.png');
        imagedestroy($img);

        $dims = $this->loader->getDimensions('test.png');

        self::assertNotNull($dims);
        self::assertSame(50, $dims[0]);
        self::assertSame(30, $dims[1]);
    }

    public function testGetDimensionsWorksWithLeadingSlash(): void
    {
        $img = imagecreatetruecolor(100, 80);
        imagepng($img, $this->tempDir.'/test2.png');
        imagedestroy($img);

        $dims = $this->loader->getDimensions('/test2.png');

        self::assertNotNull($dims);
        self::assertSame(100, $dims[0]);
        self::assertSame(80, $dims[1]);
    }

    public function testCustomBaseDirectory(): void
    {
        $subDir = $this->tempDir.'/custom';
        mkdir($subDir, 0777, true);

        $img = imagecreatetruecolor(200, 150);
        imagepng($img, $subDir.'/photo.png');
        imagedestroy($img);

        $loader = new FileLoader($subDir);
        $dims = $loader->getDimensions('photo.png');

        self::assertNotNull($dims);
        self::assertSame(200, $dims[0]);
        self::assertSame(150, $dims[1]);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
