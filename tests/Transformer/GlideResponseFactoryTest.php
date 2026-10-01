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

use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToRetrieveMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Tests\Transformer\Stub\CountingFilesystem;
use Silarhi\PicassoBundle\Transformer\GlideResponseFactory;

use function strlen;

use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GlideResponseFactoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/picasso-response-factory-test-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        (new SymfonyFilesystem())->remove($this->tempDir);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function encodedImageProvider(): iterable
    {
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00", 'image/jpeg'];
        yield 'png' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR", 'image/png'];
        yield 'gif' => ['GIF89a' . "\x01\x00\x01\x00", 'image/gif'];
        yield 'webp' => ['RIFF' . "\x24\x00\x00\x00" . 'WEBPVP8 ', 'image/webp'];
        yield 'avif' => ["\x00\x00\x00\x1C" . 'ftypavif' . "\x00\x00\x00\x00", 'image/avif'];
        yield 'heic' => ["\x00\x00\x00\x18" . 'ftypheic' . "\x00\x00\x00\x00", 'image/heic'];
        yield 'bmp' => ['BM' . "\x3A\x00\x00\x00", 'image/bmp'];
        yield 'tiff' => ["II*\x00\x08\x00\x00\x00", 'image/tiff'];
    }

    #[DataProvider('encodedImageProvider')]
    public function testTheContentTypeIsReadFromTheImageItself(string $bytes, string $mimeType): void
    {
        $storage = $this->storage(['variant' => $bytes . str_repeat("\x00", 64)]);

        $response = (new GlideResponseFactory(new Request()))->create($storage, 'variant');

        self::assertSame($mimeType, $response->headers->get('Content-Type'));
        self::assertArrayNotHasKey('mimeType', $storage->calls);
    }

    public function testAnUnknownFormatIsAskedToTheStorage(): void
    {
        $storage = $this->storage(['variant.txt' => 'not an image at all']);

        $response = (new GlideResponseFactory(new Request()))->create($storage, 'variant.txt');

        self::assertSame('text/plain', $response->headers->get('Content-Type'));
    }

    public function testAFormatNobodyCanTellIsStillServed(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, 'neither an image nor an extension');
        rewind($stream);
        $storage = self::createStub(FilesystemOperator::class);
        $storage->method('fileSize')->willReturn(33);
        $storage->method('readStream')->willReturn($stream);
        $storage->method('mimeType')->willThrowException(UnableToRetrieveMetadata::mimeType('variant'));

        $response = (new GlideResponseFactory(new Request()))->create($storage, 'variant');

        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertSame('neither an image nor an extension', $this->body($response));
    }

    public function testTheWholeImageIsSentAfterTheSniffedBytes(): void
    {
        $image = (string) file_get_contents(__DIR__ . '/../Fixtures/photo.jpg');
        $storage = $this->storage(['photo.jpg' => $image]);

        $response = (new GlideResponseFactory(new Request()))->create($storage, 'photo.jpg');

        self::assertSame($image, $this->body($response));
        self::assertSame((string) strlen($image), $response->headers->get('Content-Length'));
    }

    public function testALocalVariantCostsTwoCallsAndStillCarriesItsDate(): void
    {
        $storage = $this->storage(['photo.jpg' => (string) file_get_contents(__DIR__ . '/../Fixtures/photo.jpg')]);
        touch($this->tempDir . '/photo.jpg', 1700000000);

        $response = (new GlideResponseFactory(new Request()))->create($storage, 'photo.jpg');

        self::assertSame(['fileSize' => 1, 'readStream' => 1], $storage->calls);
        self::assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $response->headers->get('Last-Modified'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('31536000', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testARemoteVariantCostsTwoCallsAndGoesWithoutDate(): void
    {
        $image = (string) file_get_contents(__DIR__ . '/../Fixtures/photo.jpg');
        $storage = $this->storage(['photo.jpg' => $image], remote: true);

        $response = (new GlideResponseFactory(new Request()))->create($storage, 'photo.jpg');

        self::assertSame(['fileSize' => 1, 'readStream' => 1], $storage->calls);
        self::assertFalse($response->headers->has('Last-Modified'), 'The date would cost one more call.');
        self::assertSame((string) strlen($image), $response->headers->get('Content-Length'));
        self::assertSame($image, $this->body($response));
    }

    public function testANotModifiedVariantIsNeverOpened(): void
    {
        $storage = $this->storage(['photo.jpg' => 'bytes'], remote: true);
        touch($this->tempDir . '/photo.jpg', 1700000000);

        $response = (new GlideResponseFactory($this->conditionalRequest('Tue, 14 Nov 2023 22:13:20 GMT')))->create($storage, 'photo.jpg');

        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        self::assertSame(['lastModified' => 1], $storage->calls);
    }

    public function testAModifiedVariantIsSentInFull(): void
    {
        $image = (string) file_get_contents(__DIR__ . '/../Fixtures/photo.jpg');
        $storage = $this->storage(['photo.jpg' => $image], remote: true);
        touch($this->tempDir . '/photo.jpg', 1700000000);

        $response = (new GlideResponseFactory($this->conditionalRequest('Mon, 13 Nov 2023 00:00:00 GMT')))->create($storage, 'photo.jpg');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $response->headers->get('Last-Modified'));
        self::assertSame((string) strlen($image), $response->headers->get('Content-Length'));
        self::assertSame($image, $this->body($response));
    }

    public function testFromCacheReportsAMissingVariant(): void
    {
        $storage = $this->storage([]);

        self::assertNull((new GlideResponseFactory(new Request()))->fromCache($storage, 'missing.jpg'));
        self::assertNull((new GlideResponseFactory($this->conditionalRequest('Mon, 13 Nov 2023 00:00:00 GMT')))->fromCache($storage, 'missing.jpg'));
        self::assertSame(2, $storage->total(), 'One call tells a miss.');
    }

    public function testCreateThrowsWhenTheRenderIsMissing(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        (new GlideResponseFactory(new Request()))->create($this->storage([]), 'missing.jpg');
    }

    /**
     * @param array<string, string> $files
     */
    private function storage(array $files, bool $remote = false): CountingFilesystem
    {
        foreach ($files as $path => $contents) {
            file_put_contents($this->tempDir . '/' . $path, $contents);
        }

        return new CountingFilesystem($this->tempDir, $remote);
    }

    private function conditionalRequest(string $ifModifiedSince): Request
    {
        return new Request(server: ['HTTP_IF_MODIFIED_SINCE' => $ifModifiedSince]);
    }

    private function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
