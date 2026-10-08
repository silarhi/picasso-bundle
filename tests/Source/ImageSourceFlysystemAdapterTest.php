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

use function assert;

use Closure;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;
use Silarhi\PicassoBundle\Source\ImageSourceFlysystemAdapter;
use Silarhi\PicassoBundle\Source\ImageSourceInterface;
use Silarhi\PicassoBundle\Source\LocalImageSource;

class ImageSourceFlysystemAdapterTest extends TestCase
{
    private static string $fixturesDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = __DIR__ . '/../Fixtures';
    }

    public function testReadsThroughTheSource(): void
    {
        $filesystem = new Filesystem(new ImageSourceFlysystemAdapter(new LocalImageSource(self::$fixturesDir)));

        self::assertTrue($filesystem->fileExists('photo.jpg'));
        self::assertFalse($filesystem->fileExists('missing.jpg'));
        self::assertSame(file_get_contents(self::$fixturesDir . '/photo.jpg'), $filesystem->read('photo.jpg'));

        $stream = $filesystem->readStream('test.txt');
        self::assertSame(file_get_contents(self::$fixturesDir . '/test.txt'), stream_get_contents($stream));
        fclose($stream);
    }

    public function testMissingFileIsReportedAsFlysystemReadFailure(): void
    {
        $adapter = new ImageSourceFlysystemAdapter(new LocalImageSource(self::$fixturesDir));

        try {
            $adapter->read('missing.jpg');
            self::fail('Reading a missing file must fail.');
        } catch (UnableToReadFile $e) {
            self::assertInstanceOf(ImageNotFoundException::class, $e->getPrevious());
        }
    }

    public function testBundleFailureOnExistsIsReportedAsFlysystemFailure(): void
    {
        $source = self::createStub(ImageSourceInterface::class);
        $source->method('exists')->willThrowException(new ImageNotFoundException('Broken source.'));

        $this->expectException(UnableToCheckFileExistence::class);
        (new ImageSourceFlysystemAdapter($source))->fileExists('photo.jpg');
    }

    public function testASourceThatCannotTellReportsTheFileAsExisting(): void
    {
        $unavailable = new ImageSourceUnavailableException('Storage down.');
        $source = self::createStub(ImageSourceInterface::class);
        $source->method('exists')->willThrowException($unavailable);
        $source->method('readStream')->willThrowException($unavailable);
        $adapter = new ImageSourceFlysystemAdapter($source);

        // Glide would turn a failed existence check into a (cacheable) 404: the read tells the truth
        self::assertTrue($adapter->fileExists('photo.jpg'));

        try {
            $adapter->readStream('photo.jpg');
            self::fail('Reading from an unavailable source must fail.');
        } catch (UnableToReadFile $e) {
            self::assertSame($unavailable, $e->getPrevious());
        }
    }

    public function testFilesystemWrapperRejectsPathTraversal(): void
    {
        $filesystem = new Filesystem(new ImageSourceFlysystemAdapter(new LocalImageSource(self::$fixturesDir . '/Entity')));

        $this->expectException(PathTraversalDetected::class);
        $filesystem->fileExists('../photo.jpg');
    }

    public function testExposesNoDirectories(): void
    {
        $adapter = new ImageSourceFlysystemAdapter(new LocalImageSource(self::$fixturesDir));

        self::assertFalse($adapter->directoryExists('Entity'));
        self::assertSame([], $adapter->listContents('', true));
    }

    /**
     * @return iterable<string, array{Closure(ImageSourceFlysystemAdapter): void, class-string<FilesystemException>}>
     */
    public static function unsupportedOperationProvider(): iterable
    {
        yield 'write' => [static function (ImageSourceFlysystemAdapter $a): void { $a->write('a.jpg', 'x', new Config()); }, UnableToWriteFile::class];
        yield 'writeStream' => [static function (ImageSourceFlysystemAdapter $a): void { $a->writeStream('a.jpg', self::memoryStream(), new Config()); }, UnableToWriteFile::class];
        yield 'delete' => [static function (ImageSourceFlysystemAdapter $a): void { $a->delete('a.jpg'); }, UnableToDeleteFile::class];
        yield 'deleteDirectory' => [static function (ImageSourceFlysystemAdapter $a): void { $a->deleteDirectory('dir'); }, UnableToDeleteDirectory::class];
        yield 'createDirectory' => [static function (ImageSourceFlysystemAdapter $a): void { $a->createDirectory('dir', new Config()); }, UnableToCreateDirectory::class];
        yield 'setVisibility' => [static function (ImageSourceFlysystemAdapter $a): void { $a->setVisibility('a.jpg', 'public'); }, UnableToSetVisibility::class];
        yield 'visibility' => [static function (ImageSourceFlysystemAdapter $a): void { $a->visibility('photo.jpg'); }, UnableToRetrieveMetadata::class];
        yield 'mimeType' => [static function (ImageSourceFlysystemAdapter $a): void { $a->mimeType('photo.jpg'); }, UnableToRetrieveMetadata::class];
        yield 'lastModified' => [static function (ImageSourceFlysystemAdapter $a): void { $a->lastModified('photo.jpg'); }, UnableToRetrieveMetadata::class];
        yield 'fileSize' => [static function (ImageSourceFlysystemAdapter $a): void { $a->fileSize('photo.jpg'); }, UnableToRetrieveMetadata::class];
        yield 'move' => [static function (ImageSourceFlysystemAdapter $a): void { $a->move('photo.jpg', 'b.jpg', new Config()); }, UnableToMoveFile::class];
        yield 'copy' => [static function (ImageSourceFlysystemAdapter $a): void { $a->copy('photo.jpg', 'b.jpg', new Config()); }, UnableToCopyFile::class];
    }

    /**
     * @param Closure(ImageSourceFlysystemAdapter): void $operation
     * @param class-string<FilesystemException>          $expected
     */
    #[DataProvider('unsupportedOperationProvider')]
    public function testUnsupportedOperationsAreRefused(Closure $operation, string $expected): void
    {
        $this->expectException($expected);

        $operation(new ImageSourceFlysystemAdapter(new LocalImageSource(self::$fixturesDir)));
    }

    /**
     * @return resource
     */
    private static function memoryStream()
    {
        $stream = fopen('php://memory', 'r');
        assert(false !== $stream);

        return $stream;
    }
}
