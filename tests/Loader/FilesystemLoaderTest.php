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
        $loader = new FilesystemLoader(['/tmp/nonexistent']);
        $image = $loader->load(new ImageReference('/uploads/photo.jpg'));

        self::assertSame('uploads/photo.jpg', $image->path);
    }

    public function testLoadNonExistentFileHasNullStream(): void
    {
        $loader = new FilesystemLoader(['/tmp/nonexistent']);
        $image = $loader->load(new ImageReference('missing.jpg'));

        self::assertSame('missing.jpg', $image->path);
        self::assertNull($image->stream);
    }

    public function testGetSourceReturnsFirstPath(): void
    {
        $loader = new FilesystemLoader(['/var/www/uploads', '/var/www/images']);
        $source = $loader->getSource();

        self::assertSame('/var/www/uploads', $source);
    }

    public function testLoadWithNullPath(): void
    {
        $loader = new FilesystemLoader(['/tmp']);
        $image = $loader->load(new ImageReference());

        self::assertSame('', $image->path);
    }

    public function testLoadExistingFileHasStream(): void
    {
        $tmpDir = sys_get_temp_dir().'/picasso_test_'.uniqid();
        mkdir($tmpDir, 0o777, true);
        file_put_contents($tmpDir.'/test.txt', 'hello');

        try {
            $loader = new FilesystemLoader([$tmpDir]);
            $image = $loader->load(new ImageReference('test.txt'));

            self::assertSame('test.txt', $image->path);
            self::assertNotNull($image->stream);
            self::assertIsResource($image->stream);
        } finally {
            @unlink($tmpDir.'/test.txt');
            @rmdir($tmpDir);
        }
    }

    public function testLoadWithMetadataDetectsDimensions(): void
    {
        $tmpDir = sys_get_temp_dir().'/picasso_test_'.uniqid();
        mkdir($tmpDir, 0o777, true);

        // Create a minimal 1x1 GIF
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        self::assertNotFalse($gif);
        file_put_contents($tmpDir.'/pixel.gif', $gif);

        try {
            $loader = new FilesystemLoader([$tmpDir]);
            $image = $loader->load(new ImageReference('pixel.gif'), withMetadata: true);

            self::assertSame(1, $image->width);
            self::assertSame(1, $image->height);
            self::assertSame('image/gif', $image->mimeType);
        } finally {
            @unlink($tmpDir.'/pixel.gif');
            @rmdir($tmpDir);
        }
    }

    public function testLoadWithoutMetadataSkipsDetection(): void
    {
        $tmpDir = sys_get_temp_dir().'/picasso_test_'.uniqid();
        mkdir($tmpDir, 0o777, true);

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        self::assertNotFalse($gif);
        file_put_contents($tmpDir.'/pixel.gif', $gif);

        try {
            $loader = new FilesystemLoader([$tmpDir]);
            $image = $loader->load(new ImageReference('pixel.gif'));

            self::assertNull($image->width);
            self::assertNull($image->height);
            self::assertNull($image->mimeType);
            self::assertNotNull($image->stream);
        } finally {
            @unlink($tmpDir.'/pixel.gif');
            @rmdir($tmpDir);
        }
    }

    public function testLoadSearchesMultiplePaths(): void
    {
        $tmpDir1 = sys_get_temp_dir().'/picasso_test_'.uniqid();
        $tmpDir2 = sys_get_temp_dir().'/picasso_test_'.uniqid();
        mkdir($tmpDir1, 0o777, true);
        mkdir($tmpDir2, 0o777, true);
        file_put_contents($tmpDir2.'/photo.jpg', 'image-data');

        try {
            $loader = new FilesystemLoader([$tmpDir1, $tmpDir2]);
            $image = $loader->load(new ImageReference('photo.jpg'));

            self::assertSame('photo.jpg', $image->path);
            self::assertNotNull($image->stream);
            self::assertSame($tmpDir2, $image->metadata['_source']);
        } finally {
            @unlink($tmpDir2.'/photo.jpg');
            @rmdir($tmpDir1);
            @rmdir($tmpDir2);
        }
    }

    public function testSinglePathDoesNotSetSourceMetadata(): void
    {
        $tmpDir = sys_get_temp_dir().'/picasso_test_'.uniqid();
        mkdir($tmpDir, 0o777, true);
        file_put_contents($tmpDir.'/test.txt', 'hello');

        try {
            $loader = new FilesystemLoader([$tmpDir]);
            $image = $loader->load(new ImageReference('test.txt'));

            self::assertArrayNotHasKey('_source', $image->metadata);
        } finally {
            @unlink($tmpDir.'/test.txt');
            @rmdir($tmpDir);
        }
    }

    public function testLoadFirstPathTakesPriority(): void
    {
        $tmpDir1 = sys_get_temp_dir().'/picasso_test_'.uniqid();
        $tmpDir2 = sys_get_temp_dir().'/picasso_test_'.uniqid();
        mkdir($tmpDir1, 0o777, true);
        mkdir($tmpDir2, 0o777, true);
        file_put_contents($tmpDir1.'/photo.jpg', 'first');
        file_put_contents($tmpDir2.'/photo.jpg', 'second');

        try {
            $loader = new FilesystemLoader([$tmpDir1, $tmpDir2]);
            $image = $loader->load(new ImageReference('photo.jpg'));

            self::assertNotNull($image->stream);
            self::assertSame($tmpDir1, $image->metadata['_source']);
        } finally {
            @unlink($tmpDir1.'/photo.jpg');
            @unlink($tmpDir2.'/photo.jpg');
            @rmdir($tmpDir1);
            @rmdir($tmpDir2);
        }
    }
}
