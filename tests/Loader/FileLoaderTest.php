<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageDimensions;
use Silarhi\PicassoBundle\Dto\LoaderContext;
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
        $context = new LoaderContext(source: '/uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $this->loader->resolvePath($context));
    }

    public function testResolvePathReturnsAsIs(): void
    {
        $context = new LoaderContext(source: 'photo.jpg');

        self::assertSame('photo.jpg', $this->loader->resolvePath($context));
    }

    public function testGetDimensionsReturnsNullForNonExistentFile(): void
    {
        $context = new LoaderContext(source: 'nonexistent.jpg');

        self::assertNull($this->loader->getDimensions($context));
    }

    public function testGetDimensionsReturnsNullForNonImageFile(): void
    {
        file_put_contents($this->tempDir.'/file.txt', 'not an image');
        $context = new LoaderContext(source: 'file.txt');

        self::assertNull($this->loader->getDimensions($context));
    }

    public function testGetDimensionsReturnsImageDimensions(): void
    {
        $img = imagecreatetruecolor(50, 30);
        imagepng($img, $this->tempDir.'/test.png');
        imagedestroy($img);

        $context = new LoaderContext(source: 'test.png');
        $dims = $this->loader->getDimensions($context);

        self::assertInstanceOf(ImageDimensions::class, $dims);
        self::assertSame(50, $dims->width);
        self::assertSame(30, $dims->height);
    }

    public function testGetDimensionsWorksWithLeadingSlash(): void
    {
        $img = imagecreatetruecolor(100, 80);
        imagepng($img, $this->tempDir.'/test2.png');
        imagedestroy($img);

        $context = new LoaderContext(source: '/test2.png');
        $dims = $this->loader->getDimensions($context);

        self::assertInstanceOf(ImageDimensions::class, $dims);
        self::assertSame(100, $dims->width);
        self::assertSame(80, $dims->height);
    }

    public function testCustomBaseDirectory(): void
    {
        $subDir = $this->tempDir.'/custom';
        mkdir($subDir, 0777, true);

        $img = imagecreatetruecolor(200, 150);
        imagepng($img, $subDir.'/photo.png');
        imagedestroy($img);

        $loader = new FileLoader($subDir);
        $context = new LoaderContext(source: 'photo.png');
        $dims = $loader->getDimensions($context);

        self::assertInstanceOf(ImageDimensions::class, $dims);
        self::assertSame(200, $dims->width);
        self::assertSame(150, $dims->height);
    }

    public function testContextExtraIsIgnored(): void
    {
        $img = imagecreatetruecolor(50, 30);
        imagepng($img, $this->tempDir.'/extra.png');
        imagedestroy($img);

        $context = new LoaderContext(source: 'extra.png', extra: ['foo' => 'bar']);
        $dims = $this->loader->getDimensions($context);

        self::assertInstanceOf(ImageDimensions::class, $dims);
        self::assertSame(50, $dims->width);
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
