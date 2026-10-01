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

namespace Silarhi\PicassoBundle\Tests\Transformer;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Silarhi\PicassoBundle\Transformer\DeferredCacheWriter;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

class DeferredCacheWriterTest extends TestCase
{
    private string $tempDir;
    private Filesystem $render;
    private Filesystem $cache;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/picasso-deferred-writer-test-' . bin2hex(random_bytes(8));
        $this->render = new Filesystem(new LocalFilesystemAdapter($this->tempDir . '/render'));
        $this->cache = new Filesystem(new LocalFilesystemAdapter($this->tempDir . '/cache'));
    }

    protected function tearDown(): void
    {
        (new SymfonyFilesystem())->remove($this->tempDir);
    }

    public function testFlushMovesTheVariantToTheCacheStorage(): void
    {
        $this->render->write('photo.jpg/w_10.webp', 'bytes');
        $writer = new DeferredCacheWriter();
        $writer->defer($this->render, $this->cache, 'photo.jpg/w_10.webp');

        self::assertFalse($this->cache->fileExists('photo.jpg/w_10.webp'), 'Nothing is stored before the flush.');

        $writer->flush();
        $writer->flush();

        self::assertSame('bytes', $this->cache->read('photo.jpg/w_10.webp'));
        self::assertFalse($this->render->fileExists('photo.jpg/w_10.webp'));
    }

    public function testResetFlushes(): void
    {
        $this->render->write('photo.jpg/w_10.webp', 'bytes');
        $writer = new DeferredCacheWriter();
        $writer->defer($this->render, $this->cache, 'photo.jpg/w_10.webp');

        $writer->reset();

        self::assertTrue($this->cache->fileExists('photo.jpg/w_10.webp'));
    }

    public function testAFailedUploadIsLoggedAndTheLocalFileStillDeleted(): void
    {
        $this->render->write('a.jpg/w_10.webp', 'a');
        $this->render->write('b.jpg/w_10.webp', 'b');
        $failing = self::createStub(FilesystemOperator::class);
        $failing->method('writeStream')->willThrowException(UnableToWriteFile::atLocation('a.jpg/w_10.webp', 'denied'));
        $failing->method('fileExists')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('could not store'), self::callback(
            static fn (array $context): bool => 'a.jpg/w_10.webp' === $context['path'] && $context['exception'] instanceof UnableToWriteFile,
        ));

        $writer = new DeferredCacheWriter($logger);
        $writer->defer($this->render, $failing, 'a.jpg/w_10.webp');
        $writer->defer($this->render, $this->cache, 'b.jpg/w_10.webp');
        $writer->flush();

        self::assertFalse($this->render->fileExists('a.jpg/w_10.webp'), 'A worker must not accumulate local renders.');
        self::assertSame('b', $this->cache->read('b.jpg/w_10.webp'), 'One failure must not stop the other uploads.');
    }

    public function testARejectedUploadOfAVariantAlreadyStoredIsNotAnError(): void
    {
        $this->render->write('a.jpg/w_10.webp', 'a');
        $racy = self::createStub(FilesystemOperator::class);
        $racy->method('writeStream')->willThrowException(UnableToWriteFile::atLocation('a.jpg/w_10.webp', 'conflict'));
        $racy->method('fileExists')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $writer = new DeferredCacheWriter($logger);
        $writer->defer($this->render, $racy, 'a.jpg/w_10.webp');
        $writer->flush();
    }

    public function testAnUploadIsLoggedWhenTheStorageCannotEvenBeChecked(): void
    {
        $this->render->write('a.jpg/w_10.webp', 'a');
        $broken = self::createStub(FilesystemOperator::class);
        $broken->method('writeStream')->willThrowException(UnableToWriteFile::atLocation('a.jpg/w_10.webp', 'down'));
        $broken->method('fileExists')->willThrowException(UnableToReadFile::fromLocation('a.jpg/w_10.webp'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $writer = new DeferredCacheWriter($logger);
        $writer->defer($this->render, $broken, 'a.jpg/w_10.webp');
        $writer->flush();
    }

    public function testALocalFileThatCannotBeDeletedDoesNotStopTheFlush(): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, 'a');
        rewind($stream);
        $render = self::createStub(FilesystemOperator::class);
        $render->method('readStream')->willReturn($stream);
        $render->method('delete')->willThrowException(UnableToDeleteFile::atLocation('a.jpg/w_10.webp', 'busy'));
        $this->render->write('b.jpg/w_10.webp', 'b');

        $writer = new DeferredCacheWriter();
        $writer->defer($render, $this->cache, 'a.jpg/w_10.webp');
        $writer->defer($this->render, $this->cache, 'b.jpg/w_10.webp');
        $writer->flush();

        self::assertSame('a', $this->cache->read('a.jpg/w_10.webp'));
        self::assertSame('b', $this->cache->read('b.jpg/w_10.webp'));
    }

    public function testAMissingLocalFileIsLoggedAndSkipped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $writer = new DeferredCacheWriter($logger);
        $writer->defer($this->render, $this->cache, 'gone.jpg/w_10.webp');
        $writer->flush();

        self::assertFalse($this->cache->fileExists('gone.jpg/w_10.webp'));
    }
}
