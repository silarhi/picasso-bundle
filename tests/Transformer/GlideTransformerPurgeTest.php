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

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteDirectory;
use League\Glide\Server;
use League\Glide\Signatures\SignatureFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Exception\PurgeException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlideTransformerPurgeTest extends TestCase
{
    private const SIGN_KEY = 'test-secret-key';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/picasso-purge-test-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        (new SymfonyFilesystem())->remove($this->tempDir);
    }

    public function testPurgeDeletesCacheInStandardMode(): void
    {
        $transformer = $this->createTransformer($this->tempDir, false);

        // Should not throw — deleteCache silently handles missing paths
        $this->expectNotToPerformAssertions();
        $transformer->purge('uploads/photo.jpg');
    }

    public function testPurgeDeletesPublicCacheDirectory(): void
    {
        $cacheFs = new Filesystem(new LocalFilesystemAdapter($this->tempDir));

        // Simulate cached files in the public cache structure
        $cacheFs->write('glide/filesystem/uploads/photo.jpg/w_300,fm_webp.webp', 'data');
        $cacheFs->write('glide/filesystem/uploads/photo.jpg/w_600,fm_avif.avif', 'data');

        $transformer = $this->createPublicCacheTransformerWithFilesystem($cacheFs);

        $transformer->purge('uploads/photo.jpg', ['transformer' => 'glide', 'loader' => 'filesystem']);

        // fileExists instead of directoryExists: the latter requires flysystem ^3
        self::assertFalse($cacheFs->fileExists('glide/filesystem/uploads/photo.jpg/w_300,fm_webp.webp'));
        self::assertFalse($cacheFs->fileExists('glide/filesystem/uploads/photo.jpg/w_600,fm_avif.avif'));
    }

    public function testPurgePublicCacheThrowsWhenTransformerMissing(): void
    {
        $transformer = $this->createTransformer($this->tempDir, true);

        $this->expectException(TransformerNotFoundException::class);
        $this->expectExceptionMessage('transformer');

        $transformer->purge('photo.jpg', ['loader' => 'filesystem']);
    }

    public function testPurgePublicCacheThrowsWhenLoaderMissing(): void
    {
        $transformer = $this->createTransformer($this->tempDir, true);

        $this->expectException(LoaderNotFoundException::class);
        $this->expectExceptionMessage('loader');

        $transformer->purge('photo.jpg', ['transformer' => 'glide']);
    }

    public function testPurgePublicCacheDoesNotAffectOtherPaths(): void
    {
        $cacheFs = new Filesystem(new LocalFilesystemAdapter($this->tempDir));

        $cacheFs->write('glide/filesystem/uploads/photo.jpg/w_300.webp', 'data');
        $cacheFs->write('glide/filesystem/uploads/other.jpg/w_300.webp', 'other');

        $transformer = $this->createPublicCacheTransformerWithFilesystem($cacheFs);

        $transformer->purge('uploads/photo.jpg', ['transformer' => 'glide', 'loader' => 'filesystem']);

        self::assertFalse($cacheFs->fileExists('glide/filesystem/uploads/photo.jpg/w_300.webp'));
        self::assertTrue($cacheFs->fileExists('glide/filesystem/uploads/other.jpg/w_300.webp'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function cacheModeProvider(): iterable
    {
        yield 'standard cache' => [false];
        yield 'public cache' => [true];
    }

    #[DataProvider('cacheModeProvider')]
    public function testPurgeOfNeverCachedImageIsANoOp(bool $publicCache): void
    {
        $transformer = $this->createTransformer($this->tempDir, $publicCache);

        // The storage reports a missing folder as nothing to delete, not as a failure
        $this->expectNotToPerformAssertions();
        $transformer->purge('nonexistent/path.jpg', ['transformer' => 'glide', 'loader' => 'filesystem']);
    }

    #[DataProvider('cacheModeProvider')]
    public function testPurgeThrowsWhenCacheStorageCannotDeleteTheVariants(bool $publicCache): void
    {
        // Glide catches the storage's Flysystem exception and only returns false
        $cache = self::createStub(FilesystemOperator::class);
        $cache->method('deleteDirectory')->willThrowException(UnableToDeleteDirectory::atLocation('uploads/photo.jpg', 'Permission denied'));

        $transformer = $this->createTransformerWithCacheStorage($cache, $publicCache);

        try {
            $transformer->purge('uploads/photo.jpg', ['transformer' => 'glide', 'loader' => 'filesystem']);
            self::fail('The purge failure should have been reported.');
        } catch (PurgeException $e) {
            self::assertSame('Failed to purge cache for "uploads/photo.jpg": the cache storage could not delete it.', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{bool, string, array<string, string>, string}>
     */
    public static function servedVariantProvider(): iterable
    {
        yield 'public cache' => [true, 'photo.jpg/fm_webp,w_10.webp', [], 'glide/filesystem/photo.jpg'];
        yield 'standard cache' => [false, 'photo.jpg', ['w' => '10', 'fm' => 'webp'], 'photo.jpg'];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('servedVariantProvider')]
    public function testPurgeAfterServeDeletesTheServedVariant(bool $publicCache, string $servedPath, array $params, string $variantDir): void
    {
        // One instance for both calls, as in a long-running worker
        $transformer = $this->createTransformer($this->tempDir, $publicCache);

        $this->serve($transformer, $servedPath, $params);
        self::assertDirectoryExists($this->tempDir . '/' . $variantDir);

        $transformer->purge('photo.jpg', ['transformer' => 'glide', 'loader' => 'filesystem']);

        self::assertDirectoryDoesNotExist($this->tempDir . '/' . $variantDir);
    }

    public function testPurgeAndServeInterleavedOnOneInstance(): void
    {
        $transformer = $this->createTransformer($this->tempDir, true);
        $context = ['transformer' => 'glide', 'loader' => 'filesystem'];

        $this->serve($transformer, 'photo.jpg/w_10.jpg', []);
        $this->serve($transformer, 'pixel.gif/w_1.gif', []);

        $transformer->purge('photo.jpg', $context);

        self::assertFileDoesNotExist($this->tempDir . '/glide/filesystem/photo.jpg/w_10.jpg');
        self::assertFileExists($this->tempDir . '/glide/filesystem/pixel.gif/w_1.gif');

        // Serving after a purge still caches at the public path
        $this->serve($transformer, 'photo.jpg/w_10.jpg', []);
        self::assertFileExists($this->tempDir . '/glide/filesystem/photo.jpg/w_10.jpg');

        $transformer->purge('pixel.gif', $context);

        self::assertFileDoesNotExist($this->tempDir . '/glide/filesystem/pixel.gif/w_1.gif');
        self::assertFileExists($this->tempDir . '/glide/filesystem/photo.jpg/w_10.jpg');
    }

    public function testPurgeWrapsCacheStorageFailureInPurgeException(): void
    {
        // Not a Flysystem exception, so Glide lets it through
        $failure = new RuntimeException('Storage backend is unreachable.');
        $cache = self::createStub(FilesystemOperator::class);
        $cache->method('deleteDirectory')->willThrowException($failure);

        $transformer = $this->createTransformerWithCacheStorage($cache, true);

        try {
            $transformer->purge('uploads/photo.jpg', ['transformer' => 'glide', 'loader' => 'filesystem']);
            self::fail('The storage failure should have been reported.');
        } catch (PurgeException $e) {
            self::assertSame('Failed to purge cache for "uploads/photo.jpg".', $e->getMessage());
            self::assertSame($failure, $e->getPrevious());
        }
    }

    private function createTransformerWithCacheStorage(FilesystemOperator $cache, bool $publicCache): GlideTransformer
    {
        return new GlideTransformer(
            self::createStub(UrlGeneratorInterface::class),
            new UrlEncryption(self::SIGN_KEY),
            self::SIGN_KEY,
            'thumbs.storage',
            'gd',
            null,
            $publicCache,
            new FlysystemRegistry(new ServiceLocator(['thumbs.storage' => static fn (): FilesystemOperator => $cache])),
        );
    }

    private function createTransformer(string $cacheDir, bool $publicCache): GlideTransformer
    {
        $router = self::createStub(UrlGeneratorInterface::class);

        return new GlideTransformer(
            $router,
            new UrlEncryption(self::SIGN_KEY),
            self::SIGN_KEY,
            $cacheDir,
            'gd',
            null,
            $publicCache,
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function serve(GlideTransformer $transformer, string $path, array $params): void
    {
        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('getSource')->willReturn(__DIR__ . '/../Fixtures');

        $response = $transformer->serve(
            $loader,
            $path,
            new Request(SignatureFactory::create(self::SIGN_KEY)->addSignature($path, $params)),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function createPublicCacheTransformerWithFilesystem(Filesystem $cacheFs): GlideTransformer
    {
        $router = self::createStub(UrlGeneratorInterface::class);

        $transformer = new GlideTransformer(
            $router,
            new UrlEncryption(self::SIGN_KEY),
            self::SIGN_KEY,
            $this->tempDir,
            'gd',
            null,
            true,
        );

        // Replace the Glide Server's cache filesystem with our test one
        $reflection = new ReflectionClass($transformer);
        $serverProp = $reflection->getProperty('server');
        $server = $serverProp->getValue($transformer);
        assert($server instanceof Server);
        $server->setCache($cacheFs);

        return $transformer;
    }
}
