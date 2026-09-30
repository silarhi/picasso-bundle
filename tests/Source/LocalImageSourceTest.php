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

namespace Silarhi\PicassoBundle\Tests\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Source\LocalImageSource;

class LocalImageSourceTest extends TestCase
{
    private static string $fixturesDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = __DIR__ . '/../Fixtures';
    }

    public function testRootIsNormalizedWithoutTrailingSlash(): void
    {
        self::assertSame('/var/www/uploads', (new LocalImageSource('/var/www/uploads/'))->getRoot());
    }

    public function testExistsForFileInsideRoot(): void
    {
        $source = new LocalImageSource(self::$fixturesDir);

        self::assertTrue($source->exists('photo.jpg'));
        self::assertTrue($source->exists('/photo.jpg'));
        self::assertTrue($source->exists('./Entity/../photo.jpg'));
    }

    public function testDoesNotExistForMissingFileOrDirectory(): void
    {
        $source = new LocalImageSource(self::$fixturesDir);

        self::assertFalse($source->exists('missing.jpg'));
        self::assertFalse($source->exists('Entity'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPathProvider(): iterable
    {
        yield 'parent directory' => ['../Fixtures/photo.jpg'];
        yield 'nested escape' => ['Entity/../../Fixtures/photo.jpg'];
        yield 'backslash escape' => ['..\\Fixtures\\photo.jpg'];
        yield 'null byte' => ["photo.jpg\0.png"];
        yield 'empty' => [''];
        yield 'root only' => ['/'];
    }

    #[DataProvider('invalidPathProvider')]
    public function testPathsOutsideRootAreMissing(string $path): void
    {
        $source = new LocalImageSource(self::$fixturesDir);

        self::assertFalse($source->exists($path));

        $this->expectException(ImageNotFoundException::class);
        $source->readStream($path);
    }

    public function testReadStreamReturnsFileContents(): void
    {
        $stream = (new LocalImageSource(self::$fixturesDir))->readStream('test.txt');

        self::assertSame(file_get_contents(self::$fixturesDir . '/test.txt'), stream_get_contents($stream));
        fclose($stream);
    }

    public function testReadStreamThrowsForMissingFile(): void
    {
        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('Image "missing.jpg" not found.');

        (new LocalImageSource(self::$fixturesDir))->readStream('missing.jpg');
    }
}
