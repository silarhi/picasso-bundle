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

use Closure;

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

    public function testLoadDoesNotResolvePathsOutsideTheBaseDirectory(): void
    {
        $loader = new FilesystemLoader(self::$fixturesDir . '/Entity');
        $image = $loader->load(new ImageReference('../photo.jpg'));

        self::assertNull($image->stream);
    }

    public function testGetSourceReadsTheConfiguredDirectory(): void
    {
        $source = (new FilesystemLoader('/var/www/uploads/'))->getSource();

        self::assertSame('/var/www/uploads', $source->getRoot());
    }

    public function testLoadWithNullPath(): void
    {
        $loader = new FilesystemLoader('/tmp');
        $image = $loader->load(new ImageReference());

        self::assertSame('', $image->path);
    }

    public function testLoadExistingFileHasLazyStream(): void
    {
        $loader = new FilesystemLoader(self::$fixturesDir);
        $image = $loader->load(new ImageReference('test.txt'));

        self::assertSame('test.txt', $image->path);
        self::assertInstanceOf(Closure::class, $image->stream);
        $stream = ($image->stream)();
        self::assertIsResource($stream);
    }

    public function testLoadDoesNotReturnDimensionsDirectly(): void
    {
        $loader = new FilesystemLoader(self::$fixturesDir);
        $image = $loader->load(new ImageReference('pixel.gif'), withMetadata: true);

        // Loaders no longer detect dimensions — MetadataGuesser in ImageComponent handles that
        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->mimeType);
        self::assertInstanceOf(Closure::class, $image->stream);
    }
}
