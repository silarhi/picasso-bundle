<?php

namespace Silarhi\PicassoBundle\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ResolvedImage;
use Silarhi\PicassoBundle\Resolver\FilesystemResolver;

class FilesystemResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/picasso_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testResolveStripsLeadingSlash(): void
    {
        $resolver = new FilesystemResolver();
        $result = $resolver->resolve('/uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $result->path);
    }

    public function testResolveReturnsPathAsIs(): void
    {
        $resolver = new FilesystemResolver();
        $result = $resolver->resolve('photo.jpg');

        self::assertSame('photo.jpg', $result->path);
    }

    public function testResolveWithoutBaseDirectoryReturnsNullDimensions(): void
    {
        $resolver = new FilesystemResolver();
        $result = $resolver->resolve('photo.jpg');

        self::assertNull($result->width);
        self::assertNull($result->height);
    }

    public function testResolveDetectsDimensionsWithBaseDirectory(): void
    {
        $img = imagecreatetruecolor(50, 30);
        imagepng($img, $this->tempDir.'/test.png');
        imagedestroy($img);

        $resolver = new FilesystemResolver($this->tempDir);
        $result = $resolver->resolve('test.png');

        self::assertInstanceOf(ResolvedImage::class, $result);
        self::assertSame(50, $result->width);
        self::assertSame(30, $result->height);
    }

    public function testResolveReturnsNullDimensionsForNonExistentFile(): void
    {
        $resolver = new FilesystemResolver($this->tempDir);
        $result = $resolver->resolve('nonexistent.jpg');

        self::assertNull($result->width);
        self::assertNull($result->height);
    }

    public function testResolveReturnsNullDimensionsForNonImageFile(): void
    {
        file_put_contents($this->tempDir.'/file.txt', 'not an image');

        $resolver = new FilesystemResolver($this->tempDir);
        $result = $resolver->resolve('file.txt');

        self::assertNull($result->width);
        self::assertNull($result->height);
    }

    public function testResolveWorksWithLeadingSlash(): void
    {
        $img = imagecreatetruecolor(100, 80);
        imagepng($img, $this->tempDir.'/test2.png');
        imagedestroy($img);

        $resolver = new FilesystemResolver($this->tempDir);
        $result = $resolver->resolve('/test2.png');

        self::assertSame('test2.png', $result->path);
        self::assertSame(100, $result->width);
        self::assertSame(80, $result->height);
    }

    public function testResolveWithCustomBaseDirectory(): void
    {
        $subDir = $this->tempDir.'/custom';
        mkdir($subDir, 0777, true);

        $img = imagecreatetruecolor(200, 150);
        imagepng($img, $subDir.'/photo.png');
        imagedestroy($img);

        $resolver = new FilesystemResolver($subDir);
        $result = $resolver->resolve('photo.png');

        self::assertSame(200, $result->width);
        self::assertSame(150, $result->height);
    }

    public function testResolveIgnoresContext(): void
    {
        $img = imagecreatetruecolor(50, 30);
        imagepng($img, $this->tempDir.'/extra.png');
        imagedestroy($img);

        $resolver = new FilesystemResolver($this->tempDir);
        $result = $resolver->resolve('extra.png', ['foo' => 'bar']);

        self::assertSame(50, $result->width);
    }

    public function testResolveSkipsDimensionDetectionWhenSourceDimensionsProvided(): void
    {
        $img = imagecreatetruecolor(50, 30);
        imagepng($img, $this->tempDir.'/skip.png');
        imagedestroy($img);

        $resolver = new FilesystemResolver($this->tempDir);
        $result = $resolver->resolve('skip.png', [
            'sourceWidth' => 800,
            'sourceHeight' => 600,
        ]);

        self::assertSame(800, $result->width);
        self::assertSame(600, $result->height);
    }

    public function testResolveStillDetectsDimensionsWithoutBaseDirectoryWhenSourceDimensionsProvided(): void
    {
        $resolver = new FilesystemResolver();
        $result = $resolver->resolve('photo.jpg', [
            'sourceWidth' => 1024,
            'sourceHeight' => 768,
        ]);

        self::assertSame(1024, $result->width);
        self::assertSame(768, $result->height);
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
