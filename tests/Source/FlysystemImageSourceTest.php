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
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Source\FlysystemImageSource;

class FlysystemImageSourceTest extends TestCase
{
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

    public function testExposesStorage(): void
    {
        $storage = self::createStub(FilesystemOperator::class);

        self::assertSame($storage, (new FlysystemImageSource($storage))->getStorage());
    }
}
