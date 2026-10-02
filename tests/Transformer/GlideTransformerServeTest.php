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

use function assert;
use function extension_loaded;
use function in_array;
use function is_string;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Glide\Filesystem\FilesystemException;
use League\Glide\Signatures\SignatureFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\UndecodableImageException;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Silarhi\PicassoBundle\Source\FlysystemImageSource;
use Silarhi\PicassoBundle\Source\ImageSourceInterface;
use Silarhi\PicassoBundle\Source\LocalImageSource;
use Silarhi\PicassoBundle\Tests\Transformer\Stub\CountingFilesystem;
use Silarhi\PicassoBundle\Tests\Transformer\Stub\RacyCacheAdapter;
use Silarhi\PicassoBundle\Transformer\DeferredCacheWriter;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;

use function sprintf;
use function strlen;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

class GlideTransformerServeTest extends TestCase
{
    private const SIGN_KEY = 'test-secret-key';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/picasso-serve-test-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/source', 0o777, true);
        mkdir($this->tempDir . '/cache', 0o777, true);
    }

    protected function tearDown(): void
    {
        (new SymfonyFilesystem())->remove($this->tempDir);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function undecodableSourceProvider(): iterable
    {
        $png = (string) file_get_contents(__DIR__ . '/../Fixtures/2x3.png');

        // Uploads cut short: the header is there, the image data and IEND are not.
        yield 'truncated PNG' => ['truncated.png', substr($png, 0, intdiv(strlen($png), 2))];
        // A document uploaded where an image was expected.
        yield 'PDF named as an image' => ['document.jpg', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"];
    }

    #[DataProvider('undecodableSourceProvider')]
    public function testServeThrowsUndecodableImageExceptionWhenSourceCannotBeDecoded(string $filename, string $contents): void
    {
        file_put_contents($this->tempDir . '/source/' . $filename, $contents);

        $transformer = $this->createTransformer($this->tempDir . '/cache');

        $this->expectException(UndecodableImageException::class);

        $transformer->serve(
            $this->createLoader($this->tempDir . '/source'),
            $filename,
            $this->createSignedRequest($filename, ['w' => '10', 'fm' => 'webp']),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );
    }

    public function testServeReturnsCachedImageWhenAConcurrentRequestWroteItFirst(): void
    {
        // The concurrent request wins the race: the variant lands in the cache,
        // then the storage rejects this request's own write of it.
        $cacheAdapter = new RacyCacheAdapter($this->tempDir . '/cache', writesBeforeFailing: true);
        $transformer = $this->createTransformer('racy.storage', $this->createFlysystemRegistry('racy.storage', $cacheAdapter));

        $response = $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            'photo.jpg',
            $this->createSignedRequest('photo.jpg', ['w' => '10', 'fm' => 'webp']),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertSame(1, $cacheAdapter->writeAttempts, 'The cached variant must be served, not rendered again.');
    }

    public function testServeRethrowsCacheWriteFailureWhenNothingWasCached(): void
    {
        $cacheAdapter = new RacyCacheAdapter($this->tempDir . '/cache', writesBeforeFailing: false);
        $transformer = $this->createTransformer('racy.storage', $this->createFlysystemRegistry('racy.storage', $cacheAdapter));

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('Could not write the image');

        $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            'photo.jpg',
            $this->createSignedRequest('photo.jpg', ['w' => '10', 'fm' => 'webp']),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );
    }

    public function testServeReadsSourceFromFilesystemOperator(): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache');

        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('getSource')->willReturn(new FlysystemImageSource(new Filesystem(new LocalFilesystemAdapter(__DIR__ . '/../Fixtures'))));

        $response = $transformer->serve(
            $loader,
            'photo.jpg',
            $this->createSignedRequest('photo.jpg', ['w' => '10', 'fm' => 'webp']),
            ['transformer' => 'glide', 'loader' => 'flysystem'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
    }

    public function testServeReadsSourceFromCustomImageSource(): void
    {
        $contents = (string) file_get_contents(__DIR__ . '/../Fixtures/photo.jpg');
        $source = new class($contents) implements ImageSourceInterface {
            public function __construct(private readonly string $contents)
            {
            }

            public function exists(string $path): bool
            {
                return 'db/42.jpg' === $path;
            }

            public function readStream(string $path)
            {
                $stream = fopen('php://memory', 'r+');
                assert(false !== $stream);
                fwrite($stream, $this->contents);
                rewind($stream);

                return $stream;
            }
        };

        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('getSource')->willReturn($source);

        $response = $this->createTransformer($this->tempDir . '/cache')->serve(
            $loader,
            'db/42.jpg',
            $this->createSignedRequest('db/42.jpg', ['w' => '10', 'fm' => 'webp']),
            ['transformer' => 'glide', 'loader' => 'custom'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
    }

    public function testServeThrowsImageNotFoundForPathEscapingTheSource(): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache');

        $this->expectException(ImageNotFoundException::class);

        $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures/Entity'),
            '../photo.jpg',
            $this->createSignedRequest('../photo.jpg', ['w' => '10']),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );
    }

    public function testServeRethrowsUnexpectedErrorsUnchanged(): void
    {
        // Not a Flysystem exception, so Glide lets it through untouched
        $failure = new RuntimeException('Storage backend is unreachable.');
        $source = self::createStub(FilesystemOperator::class);
        $source->method('fileExists')->willThrowException($failure);

        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('getSource')->willReturn(new FlysystemImageSource($source));

        $transformer = $this->createTransformer($this->tempDir . '/cache');

        try {
            $transformer->serve(
                $loader,
                'photo.jpg',
                $this->createSignedRequest('photo.jpg', ['w' => '10', 'fm' => 'webp']),
                ['transformer' => 'glide', 'loader' => 'flysystem'],
            );
            self::fail('The storage failure should have been rethrown.');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e, 'Errors that are not decoding failures must not be wrapped.');
        }
    }

    public function testServeRejectsAnEmptyPath(): void
    {
        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('Image not found.');

        $this->createTransformer($this->tempDir . '/cache')->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            '',
            $this->createSignedRequest('', ['w' => '10']),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );
    }

    public function testServeRejectsPublicCachePathWithoutSourcePath(): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true);

        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('Invalid cached image path.');

        // A bare params segment: nothing is left to name the source image
        $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            'w_10.jpg',
            $this->createSignedRequest('w_10.jpg', []),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );
    }

    public function testRedirectToLoaderKeepsTheTransformation(): void
    {
        $response = $this->createTransformer($this->tempDir . '/cache')->redirectToLoader(
            'uploads',
            'users/42.jpg',
            // Params the signature covers but Glide does not know (1.x "_metadata") are dropped
            $this->createSignedRequest('users/42.jpg', ['w' => '10', '_metadata' => 'pre-2.0-token']),
            ['transformer' => 'glide', 'loader' => 'vich'],
        );

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        $location = (string) $response->headers->get('Location');
        self::assertSame('/picasso/glide/uploads/users/42.jpg', parse_url($location, \PHP_URL_PATH));
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame(['w', 's'], array_keys($query));
    }

    public function testRedirectToLoaderKeepsPublicCacheParamsInThePath(): void
    {
        $response = $this->createTransformer($this->tempDir . '/cache', publicCache: true)->redirectToLoader(
            'uploads',
            'users/42.jpg/fm_webp,w_10.webp',
            $this->createSignedRequest('users/42.jpg/fm_webp,w_10.webp', ['_metadata' => 'pre-2.0-token']),
            ['transformer' => 'glide', 'loader' => 'vich'],
        );

        $location = (string) $response->headers->get('Location');
        self::assertSame('/picasso/glide/uploads/users/42.jpg/fm_webp%2Cw_10.webp', parse_url($location, \PHP_URL_PATH));
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame(['s'], array_keys($query));
    }

    public function testRedirectToLoaderMovesQueryParamsIntoThePathInPublicCacheMode(): void
    {
        // A URL minted before public cache was enabled keeps its params in the query string
        $response = $this->createTransformer($this->tempDir . '/cache', publicCache: true)->redirectToLoader(
            'uploads',
            'photo.jpg',
            $this->createSignedRequest('photo.jpg', ['w' => '10', 'fm' => 'webp', '_metadata' => 'pre-2.0-token']),
            ['transformer' => 'glide', 'loader' => 'vich'],
        );

        $location = (string) $response->headers->get('Location');
        self::assertSame('/picasso/glide/uploads/photo.jpg/fm_webp%2Cw_10.webp', parse_url($location, \PHP_URL_PATH));
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame(['s'], array_keys($query), 'Only the signature stays in the query string');
    }

    public function testRedirectToLoaderRejectsAnInvalidSignature(): void
    {
        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('Invalid image signature.');

        $this->createTransformer($this->tempDir . '/cache')->redirectToLoader(
            'uploads',
            'photo.jpg',
            new Request(['w' => '10', '_metadata' => 'pre-2.0-token', 's' => 'forged']),
            ['transformer' => 'glide', 'loader' => 'vich'],
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function untransformedImagePathProvider(): iterable
    {
        yield 'filename with extension' => ['uploads/photo.jpg', '_untransformed.jpg'];
        yield 'filename without extension' => ['uploads/photo', '_untransformed.'];
    }

    #[DataProvider('untransformedImagePathProvider')]
    public function testServeServesUntransformedPublicCacheUrl(string $path, string $expectedCacheFilename): void
    {
        $this->copyFixtureToSource($path);
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true);
        $context = ['transformer' => 'glide', 'loader' => 'filesystem'];

        $url = $transformer->url(new Image(path: $path), new ImageTransformation(), $context);
        $response = $this->serveUrl($transformer, $url);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), 'A URL minted by url() must be served, not redirected.');
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));

        // Cached like any other variant, inside the image's folder, so purging the image removes it
        $variantDir = $this->tempDir . '/cache/glide/filesystem/' . $path;
        self::assertFileExists($variantDir . '/' . $expectedCacheFilename);

        // Purge from a fresh instance, as a separate request would
        $this->createTransformer($this->tempDir . '/cache', publicCache: true)->purge($path, $context);

        self::assertDirectoryDoesNotExist($variantDir);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyParamsSegmentPathProvider(): iterable
    {
        yield 'dotfile params filename' => ['photo.jpg/.jpg'];
        yield 'dot-segment' => ['photo/.'];
    }

    #[DataProvider('emptyParamsSegmentPathProvider')]
    public function testServeRejectsEmptyParamsSegment(string $path): void
    {
        // Neither a params segment nor an image filename: redirecting would loop
        // (each hop appends a segment), serving would cache outside the variant folder.
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true);

        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('Invalid cached image filename.');

        $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            $path,
            $this->createSignedRequest($path, []),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function untransformedLegacyPathProvider(): iterable
    {
        // "my" is not a transformation param, so this is an image filename, not a params segment
        yield 'underscore in filename' => ['uploads/my_photo.jpg', 'uploads/my_photo.jpg/_untransformed.jpg'];
        yield 'filename without extension' => ['uploads/photo', 'uploads/photo/_untransformed.'];
    }

    #[DataProvider('untransformedLegacyPathProvider')]
    public function testServeRedirectsUntransformedLegacyUrl(string $path, string $canonicalPath): void
    {
        $this->copyFixtureToSource($path);
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true);

        $response = $transformer->serve(
            $this->createLoader($this->tempDir . '/source'),
            $path,
            $this->createSignedRequest($path, []),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());

        $location = (string) $response->headers->get('Location');
        $signature = SignatureFactory::create(self::SIGN_KEY)->generateSignature($canonicalPath, []);
        self::assertSame('/picasso/glide/filesystem/' . $canonicalPath . '?s=' . $signature, $location);

        // The redirect target must be served, or clients would loop through redirects
        $followed = $this->serveUrl($transformer, $location);
        self::assertSame(Response::HTTP_OK, $followed->getStatusCode(), 'A legacy redirect must not lead to another redirect.');
    }

    public function testServeStoresPublicCacheVariantsUnderTheCachePrefix(): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true, cachePrefix: '/image/');

        $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            'photo.jpg/fm_webp,w_10.webp',
            $this->createSignedRequest('photo.jpg/fm_webp,w_10.webp', []),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );

        self::assertFileExists($this->tempDir . '/cache/image/glide/filesystem/photo.jpg/fm_webp,w_10.webp');
    }

    public function testServeMovesADeferredMissToTheCacheOnlyWhenFlushed(): void
    {
        $writer = new DeferredCacheWriter();
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true, deferredCacheWriter: $writer);
        $variant = $this->tempDir . '/cache/glide/filesystem/photo.jpg/fm_webp,w_10.webp';

        $response = $this->servePublicCacheFixture($transformer);
        $body = $this->responseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertFileDoesNotExist($variant, 'The variant must be stored after the response is sent, not before.');

        $writer->flush();

        self::assertSame($body, file_get_contents($variant));
    }

    public function testServeAnswersAHitFromTheCacheStorageWithDeferredWrites(): void
    {
        $writer = new DeferredCacheWriter();
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true, deferredCacheWriter: $writer);
        $variant = $this->tempDir . '/cache/glide/filesystem/photo.jpg/fm_webp,w_10.webp';
        $this->servePublicCacheFixture($transformer);
        $writer->flush();
        // Marks the stored copy, to tell it apart from a fresh render
        file_put_contents($variant, 'stored');

        $response = $this->servePublicCacheFixture($transformer);
        $writer->flush();

        self::assertSame('stored', $this->responseBody($response));
        self::assertSame('stored', file_get_contents($variant), 'A hit must not be rendered and uploaded again.');
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function cacheModeProvider(): iterable
    {
        yield 'default' => [false, false];
        yield 'public cache' => [true, false];
        yield 'deferred writes' => [false, true];
        yield 'public cache, deferred writes' => [true, true];
    }

    #[DataProvider('cacheModeProvider')]
    public function testAHitCostsTwoStorageCalls(bool $publicCache, bool $deferred): void
    {
        $cache = new CountingFilesystem($this->tempDir . '/cache');
        $writer = $deferred ? new DeferredCacheWriter() : null;
        $transformer = $this->createTransformer('counting.storage', $this->createRegistryOf('counting.storage', $cache), publicCache: $publicCache, deferredCacheWriter: $writer);
        $path = $publicCache ? 'photo.jpg/fm_webp,w_10.webp' : 'photo.jpg';
        $params = $publicCache ? [] : ['w' => '10', 'fm' => 'webp'];
        $context = ['transformer' => 'glide', 'loader' => 'filesystem'];
        $loader = $this->createLoader(__DIR__ . '/../Fixtures');
        $transformer->serve($loader, $path, $this->createSignedRequest($path, $params), $context);
        $writer?->flush();
        $cache->reset();

        $response = $transformer->serve($loader, $path, $this->createSignedRequest($path, $params), $context);
        $body = $this->responseBody($response);

        self::assertSame(['fileSize' => 1, 'readStream' => 1], $cache->calls, 'Glide alone asks for existence, stream, mime type, size and date.');
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertSame((string) strlen($body), $response->headers->get('Content-Length'));
    }

    public function testANotModifiedHitNeverOpensTheVariant(): void
    {
        $cache = new CountingFilesystem($this->tempDir . '/cache');
        $transformer = $this->createTransformer('counting.storage', $this->createRegistryOf('counting.storage', $cache));
        $loader = $this->createLoader(__DIR__ . '/../Fixtures');
        $context = ['transformer' => 'glide', 'loader' => 'filesystem'];
        $params = ['w' => '10', 'fm' => 'webp'];
        $lastModified = (string) $transformer->serve($loader, 'photo.jpg', $this->createSignedRequest('photo.jpg', $params), $context)->headers->get('Last-Modified');
        $cache->reset();

        $request = $this->createSignedRequest('photo.jpg', $params);
        $request->headers->set('If-Modified-Since', $lastModified);
        $response = $transformer->serve($loader, 'photo.jpg', $request, $context);

        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        self::assertSame(['lastModified' => 1], $cache->calls);
    }

    public function testDeferredWritesLeaveNoRenderDirectoryBehind(): void
    {
        $writer = new DeferredCacheWriter();
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true, deferredCacheWriter: $writer);
        $renderDirectory = (new ReflectionProperty(GlideTransformer::class, 'renderDirectory'))->getValue($transformer);
        self::assertIsString($renderDirectory);

        $transformer->url(new Image(path: 'photo.jpg'), new ImageTransformation(width: 10), ['transformer' => 'glide', 'loader' => 'filesystem']);
        self::assertDirectoryDoesNotExist($renderDirectory, 'An instance that renders nothing must not create it: PHP-FPM builds one per request.');

        $this->servePublicCacheFixture($transformer);
        self::assertDirectoryExists($renderDirectory);

        $writer->flush();
        self::assertDirectoryDoesNotExist($renderDirectory, 'The variant folders Glide created must go too.');
        self::assertFileExists($this->tempDir . '/cache/glide/filesystem/photo.jpg/fm_webp,w_10.webp');

        // A long-running worker renders again into the same directory
        unlink($this->tempDir . '/cache/glide/filesystem/photo.jpg/fm_webp,w_10.webp');
        self::assertSame(200, $this->servePublicCacheFixture($transformer)->getStatusCode());
        $writer->flush();
        self::assertFileExists($this->tempDir . '/cache/glide/filesystem/photo.jpg/fm_webp,w_10.webp');
        self::assertDirectoryDoesNotExist($renderDirectory);
    }

    public function testAnUncontendedMissRendersUnderTheLock(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');
        $transformer = $this->createTransformer($this->tempDir . '/cache', lockFactory: $this->createLockFactory($lock));

        $response = $this->serveFixture($transformer);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
    }

    public function testAMissWaitsForTheRenderInProgressAndServesIt(): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache', lockFactory: $this->createLockFactory($lock = $this->createMock(SharedLockInterface::class)));
        $attempts = 0;
        $lock->expects(self::exactly(3))->method('acquire')->with(false)->willReturnCallback(function () use (&$attempts): bool {
            if (++$attempts < 3) {
                return false;
            }

            // The other process stores its render, then releases the lock
            $this->storeEveryVariantAs((string) file_get_contents(__DIR__ . '/../Fixtures/2x3.png'));

            return true;
        });
        $lock->expects(self::once())->method('release');

        $response = $this->serveFixture($transformer);

        self::assertSame((string) file_get_contents(__DIR__ . '/../Fixtures/2x3.png'), $this->responseBody($response), 'The variant must be rendered once.');
    }

    public function testAMissRendersItselfWhenTheLockHolderStoredNothing(): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache', lockFactory: $this->createLockFactory($lock = $this->createMock(SharedLockInterface::class)));
        // The lock holder crashed: its lock expired, and no variant was stored
        $lock->expects(self::exactly(2))->method('acquire')->with(false)->willReturnOnConsecutiveCalls(false, true);
        $lock->expects(self::once())->method('release');

        $response = $this->serveFixture($transformer);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('RIFF', $this->responseBody($response));
    }

    public function testAMissRendersItselfOnceTheWaitIsOver(): void
    {
        $writer = new DeferredCacheWriter();
        $transformer = $this->createTransformer($this->tempDir . '/cache', deferredCacheWriter: $writer, lockFactory: $this->createLockFactory($lock = $this->createMock(SharedLockInterface::class)), lockWait: 0.3);
        // The lock holder is stuck (e.g. on a stalled upload) and keeps its lock
        $lock->expects(self::atLeastOnce())->method('acquire')->with(false)->willReturn(false);
        $lock->expects(self::never())->method('release');

        $start = microtime(true);
        $response = $this->serveFixture($transformer);
        $waited = microtime(true) - $start;
        $writer->flush();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('RIFF', $this->responseBody($response));
        self::assertGreaterThanOrEqual(0.3, $waited, 'The miss must wait for the render in progress first.');
        self::assertLessThan(2.0, $waited, 'The wait must end after lock.wait, not when the holder lets go.');
        self::assertNotEmpty(glob($this->tempDir . '/cache/photo.jpg/*'), 'The variant rendered without the lock is stored as well.');
    }

    public function testAMissDoesNotWaitWhenTheWaitIsZero(): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache', lockFactory: $this->createLockFactory($lock = $this->createMock(SharedLockInterface::class)), lockWait: 0.0);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(false);
        $lock->expects(self::never())->method('release');

        $response = $this->serveFixture($transformer);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testADeferredRenderReleasesItsLockOnFlush(): void
    {
        $writer = new DeferredCacheWriter();
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn(true);
        $transformer = $this->createTransformer($this->tempDir . '/cache', deferredCacheWriter: $writer, lockFactory: $this->createLockFactory($lock));
        $released = 0;
        $lock->expects(self::once())->method('release')->willReturnCallback(function () use (&$released): void {
            self::assertNotEmpty(glob($this->tempDir . '/cache/photo.jpg/*'), 'Waiting requests must find the variant once the lock is released.');
            ++$released;
        });

        $this->serveFixture($transformer);
        self::assertSame(0, $released, 'Waiting requests would not find the variant in the cache storage yet.');

        $writer->flush();
        self::assertSame(1, $released);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function driverProvider(): iterable
    {
        yield 'gd' => ['gd'];
        yield 'imagick' => ['imagick'];
        yield 'vips' => ['vips'];
    }

    #[DataProvider('driverProvider')]
    public function testEveryDriverRendersAVariant(string $driver): void
    {
        $driverClass = GlideTransformer::driverClass($driver);
        if ('imagick' === $driver && !extension_loaded('imagick')) {
            self::markTestSkipped('The imagick extension is not loaded.');
        }
        if (null !== $driverClass && !class_exists($driverClass)) {
            self::markTestSkipped(sprintf('The "%s" driver package is not installed.', $driver));
        }

        try {
            $transformer = $this->createTransformer($this->tempDir . '/cache', driver: $driver);
        } catch (Throwable $e) {
            self::markTestSkipped(sprintf('The "%s" driver cannot run here: %s', $driver, $e->getMessage()));
        }

        $response = $this->serveFixture($transformer);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        $size = getimagesizefromstring($this->responseBody($response));
        self::assertIsArray($size);
        self::assertSame([10, 5], [$size[0], $size[1]]);
    }

    public function testAHitTakesNoLock(): void
    {
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::never())->method('createLock');
        $this->serveFixture($this->createTransformer($this->tempDir . '/cache'));

        $this->serveFixture($this->createTransformer($this->tempDir . '/cache', lockFactory: $lockFactory));
    }

    private function serveFixture(GlideTransformer $transformer): Response
    {
        return $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            'photo.jpg',
            $this->createSignedRequest('photo.jpg', ['w' => '10', 'fm' => 'webp']),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );
    }

    /**
     * Renders and stores the variant serveFixture() asks for, as another process
     * would, then marks it to tell it apart from a render of this process.
     */
    private function storeEveryVariantAs(string $contents): void
    {
        $this->serveFixture($this->createTransformer($this->tempDir . '/cache'));
        $variants = glob($this->tempDir . '/cache/photo.jpg/*');
        self::assertIsArray($variants);
        self::assertNotEmpty($variants);
        foreach ($variants as $variant) {
            file_put_contents($variant, $contents);
        }
    }

    private function createLockFactory(SharedLockInterface $lock): LockFactory
    {
        $lockFactory = self::createStub(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        return $lockFactory;
    }

    private function createRegistryOf(string $storageName, FilesystemOperator $storage): FlysystemRegistry
    {
        return new FlysystemRegistry(new ServiceLocator([
            $storageName => static fn (): FilesystemOperator => $storage,
        ]));
    }

    private function servePublicCacheFixture(GlideTransformer $transformer): Response
    {
        return $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            'photo.jpg/fm_webp,w_10.webp',
            $this->createSignedRequest('photo.jpg/fm_webp,w_10.webp', []),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );
    }

    private function responseBody(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function createTransformer(string $cache, ?FlysystemRegistry $flysystemRegistry = null, bool $publicCache = false, string $cachePrefix = '', ?DeferredCacheWriter $deferredCacheWriter = null, ?LockFactory $lockFactory = null, string $driver = 'gd', float $lockWait = 10.0): GlideTransformer
    {
        $router = self::createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static function (string $name, array $params): string {
            assert(is_string($params['transformer']));
            assert(is_string($params['loader']));
            assert(is_string($params['path']));

            $base = '/picasso/' . $params['transformer'] . '/' . $params['loader'] . '/' . $params['path'];
            $query = array_filter($params, static fn ($key): bool => !in_array($key, ['transformer', 'loader', 'path'], true), \ARRAY_FILTER_USE_KEY);

            return $base . '?' . http_build_query($query);
        });

        return new GlideTransformer(
            $router,
            new UrlAliases([], []),
            self::SIGN_KEY,
            $cache,
            $driver,
            null,
            $publicCache,
            $flysystemRegistry,
            null,
            $cachePrefix,
            $deferredCacheWriter,
            $lockFactory,
            30.0,
            $lockWait,
        );
    }

    private function createFlysystemRegistry(string $storageName, FilesystemAdapter $adapter): FlysystemRegistry
    {
        return new FlysystemRegistry(new ServiceLocator([
            $storageName => static fn (): Filesystem => new Filesystem($adapter),
        ]));
    }

    private function createLoader(string $sourceDir): ServableLoaderInterface
    {
        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('getSource')->willReturn(new LocalImageSource($sourceDir));

        return $loader;
    }

    private function copyFixtureToSource(string $path): void
    {
        (new SymfonyFilesystem())->copy(__DIR__ . '/../Fixtures/photo.jpg', $this->tempDir . '/source/' . $path);
    }

    /**
     * Serve a URL minted by the transformer, as the image controller would route it.
     */
    private function serveUrl(GlideTransformer $transformer, string $url): Response
    {
        $prefix = '/picasso/glide/filesystem/';
        $urlPath = (string) parse_url($url, \PHP_URL_PATH);
        self::assertStringStartsWith($prefix, $urlPath);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return $transformer->serve(
            $this->createLoader($this->tempDir . '/source'),
            rawurldecode(substr($urlPath, strlen($prefix))),
            new Request($query),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function createSignedRequest(string $path, array $params): Request
    {
        return new Request(SignatureFactory::create(self::SIGN_KEY)->addSignature($path, $params));
    }
}
