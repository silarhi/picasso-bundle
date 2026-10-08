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

use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;
use Silarhi\PicassoBundle\Source\FlysystemImageSource;
use Silarhi\PicassoBundle\Tests\Source\Stub\ObjectStoreFailures;

class FlysystemImageSourceTest extends TestCase
{
    use ObjectStoreFailures;

    public function testDelegatesExistsToStorage(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('fileExists')->with('photo.jpg')->willReturn(true);

        self::assertTrue((new FlysystemImageSource($storage))->exists('photo.jpg'));
    }

    public function testFlysystemFailureOnExistsReportsMissing(): void
    {
        $storage = self::createStub(FilesystemOperator::class);
        $storage->method('fileExists')->willThrowException(UnableToCheckFileExistence::forLocation('photo.jpg'));

        self::assertFalse((new FlysystemImageSource($storage))->exists('photo.jpg'));
    }

    public function testOtherFailuresOnExistsPropagate(): void
    {
        $failure = new RuntimeException('Storage backend is unreachable.');
        $storage = self::createStub(FilesystemOperator::class);
        $storage->method('fileExists')->willThrowException($failure);

        $this->expectExceptionObject($failure);
        (new FlysystemImageSource($storage))->exists('photo.jpg');
    }

    public function testReadStreamDelegatesToStorage(): void
    {
        $stream = fopen('php://memory', 'r');
        self::assertIsResource($stream);

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('readStream')->with('photo.jpg')->willReturn($stream);

        self::assertSame($stream, (new FlysystemImageSource($storage))->readStream('photo.jpg'));
    }

    public function testReadStreamWrapsFlysystemFailure(): void
    {
        $failure = UnableToReadFile::fromLocation('photo.jpg');
        $storage = self::createStub(FilesystemOperator::class);
        $storage->method('readStream')->willThrowException($failure);

        try {
            (new FlysystemImageSource($storage))->readStream('photo.jpg');
            self::fail('A read failure must be reported as a missing image.');
        } catch (ImageNotFoundException $e) {
            self::assertSame($failure, $e->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{int|null}>
     */
    public static function unavailableStorageProvider(): iterable
    {
        yield 'no answer (connection refused, timeout)' => [null];
        yield 'server error' => [500];
        yield 'slow down' => [503];
        yield 'too many requests' => [429];
    }

    #[DataProvider('unavailableStorageProvider')]
    public function testAnUnavailableStorageOnReadIsNotAMissingImage(?int $status): void
    {
        $failure = UnableToReadFile::fromLocation('photo.jpg', 'GetObject failed', self::objectStoreException($status));
        $storage = self::createStub(FilesystemOperator::class);
        $storage->method('readStream')->willThrowException($failure);

        try {
            (new FlysystemImageSource($storage))->readStream('photo.jpg');
            self::fail('An unavailable storage must not be reported as a missing image.');
        } catch (ImageSourceUnavailableException $e) {
            self::assertSame($failure, $e->getPrevious());
        }
    }

    #[DataProvider('unavailableStorageProvider')]
    public function testAnUnavailableStorageOnExistsCannotTell(?int $status): void
    {
        $failure = UnableToCheckFileExistence::forLocation('photo.jpg', self::objectStoreException($status));
        $storage = self::createStub(FilesystemOperator::class);
        $storage->method('fileExists')->willThrowException($failure);

        try {
            (new FlysystemImageSource($storage))->exists('photo.jpg');
            self::fail('An unavailable storage must not be reported as a missing image.');
        } catch (ImageSourceUnavailableException $e) {
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function testAnObjectStoreAnsweringNotFoundReportsAMissingImage(): void
    {
        $storage = self::createStub(FilesystemOperator::class);
        $storage->method('readStream')->willThrowException(UnableToReadFile::fromLocation('photo.jpg', 'NoSuchKey', self::objectStoreException(404)));
        $storage->method('fileExists')->willThrowException(UnableToCheckFileExistence::forLocation('photo.jpg', self::objectStoreException(403)));
        $source = new FlysystemImageSource($storage);

        self::assertFalse($source->exists('photo.jpg'));
        $this->expectException(ImageNotFoundException::class);
        $source->readStream('photo.jpg');
    }

    public function testExposesStorage(): void
    {
        $storage = self::createStub(FilesystemOperator::class);

        self::assertSame($storage, (new FlysystemImageSource($storage))->getStorage());
    }
}
