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

use function dirname;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\FilesystemLoader;

class FilesystemLoaderTest extends TestCase
{
    private static string $fixturesDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = dirname(__DIR__) . '/Fixtures';
    }

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
        $loader = new FilesystemLoader([self::$fixturesDir]);
        $image = $loader->load(new ImageReference('test.txt'));

        self::assertSame('test.txt', $image->path);
        self::assertNotNull($image->stream);
        self::assertIsResource($image->stream);
    }

    public function testLoadWithMetadataDetectsDimensions(): void
    {
        $loader = new FilesystemLoader([self::$fixturesDir]);
        $image = $loader->load(new ImageReference('pixel.gif'), withMetadata: true);

        self::assertSame(1, $image->width);
        self::assertSame(1, $image->height);
        self::assertSame('image/gif', $image->mimeType);
    }

    public function testLoadWithoutMetadataSkipsDetection(): void
    {
        $loader = new FilesystemLoader([self::$fixturesDir]);
        $image = $loader->load(new ImageReference('pixel.gif'));

        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->mimeType);
        self::assertNotNull($image->stream);
    }

    public function testLoadSearchesMultiplePaths(): void
    {
        $tmpDir1 = sys_get_temp_dir() . '/picasso_test_' . uniqid();
        $tmpDir2 = sys_get_temp_dir() . '/picasso_test_' . uniqid();
        mkdir($tmpDir1, 0o777, true);
        mkdir($tmpDir2, 0o777, true);
        file_put_contents($tmpDir2 . '/photo.jpg', 'image-data');

        try {
            $loader = new FilesystemLoader([$tmpDir1, $tmpDir2]);
            $image = $loader->load(new ImageReference('photo.jpg'));

            self::assertSame('photo.jpg', $image->path);
            self::assertNotNull($image->stream);
            self::assertSame($tmpDir2, $image->metadata['_source']);
        } finally {
            @unlink($tmpDir2 . '/photo.jpg');
            @rmdir($tmpDir1);
            @rmdir($tmpDir2);
        }
    }

    public function testSinglePathDoesNotSetSourceMetadata(): void
    {
        $tmpDir = sys_get_temp_dir() . '/picasso_test_' . uniqid();
        mkdir($tmpDir, 0o777, true);
        file_put_contents($tmpDir . '/test.txt', 'hello');

        try {
            $loader = new FilesystemLoader([$tmpDir]);
            $image = $loader->load(new ImageReference('test.txt'));

            self::assertArrayNotHasKey('_source', $image->metadata);
        } finally {
            @unlink($tmpDir . '/test.txt');
            @rmdir($tmpDir);
        }
    }

    public function testLoadFirstPathTakesPriority(): void
    {
        $tmpDir1 = sys_get_temp_dir() . '/picasso_test_' . uniqid();
        $tmpDir2 = sys_get_temp_dir() . '/picasso_test_' . uniqid();
        mkdir($tmpDir1, 0o777, true);
        mkdir($tmpDir2, 0o777, true);
        file_put_contents($tmpDir1 . '/photo.jpg', 'first');
        file_put_contents($tmpDir2 . '/photo.jpg', 'second');

        try {
            $loader = new FilesystemLoader([$tmpDir1, $tmpDir2]);
            $image = $loader->load(new ImageReference('photo.jpg'));

            self::assertNotNull($image->stream);
            self::assertSame($tmpDir1, $image->metadata['_source']);
        } finally {
            @unlink($tmpDir1 . '/photo.jpg');
            @unlink($tmpDir2 . '/photo.jpg');
            @rmdir($tmpDir1);
            @rmdir($tmpDir2);
        }
    }
}
