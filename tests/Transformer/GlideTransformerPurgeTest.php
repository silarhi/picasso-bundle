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
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Glide\Server;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
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

        self::assertFalse($cacheFs->directoryExists('glide/filesystem/uploads/photo.jpg'));
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

        self::assertFalse($cacheFs->directoryExists('glide/filesystem/uploads/photo.jpg'));
        self::assertTrue($cacheFs->fileExists('glide/filesystem/uploads/other.jpg/w_300.webp'));
    }

    public function testPurgeStandardModeHandlesNonExistentPath(): void
    {
        $transformer = $this->createTransformer($this->tempDir, false);

        // deleteCache on a non-existent path returns false but does not throw
        $this->expectNotToPerformAssertions();
        $transformer->purge('nonexistent/path.jpg');
    }

    private function createTransformer(string $cacheDir, bool $publicCache): GlideTransformer
    {
        $router = $this->createMock(UrlGeneratorInterface::class);

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

    private function createPublicCacheTransformerWithFilesystem(Filesystem $cacheFs): GlideTransformer
    {
        $router = $this->createMock(UrlGeneratorInterface::class);

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
