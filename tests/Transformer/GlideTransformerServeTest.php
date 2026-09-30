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
use League\Flysystem\FilesystemAdapter;
use League\Glide\Filesystem\FilesystemException;
use League\Glide\Signatures\SignatureFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Exception\UndecodableImageException;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Tests\Transformer\Stub\RacyCacheAdapter;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;

use function strlen;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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

    private function createTransformer(string $cache, ?FlysystemRegistry $flysystemRegistry = null): GlideTransformer
    {
        return new GlideTransformer(
            self::createStub(UrlGeneratorInterface::class),
            new UrlEncryption(self::SIGN_KEY),
            self::SIGN_KEY,
            $cache,
            'gd',
            null,
            false,
            $flysystemRegistry,
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
        $loader->method('getSource')->willReturn($sourceDir);

        return $loader;
    }

    /**
     * @param array<string, string> $params
     */
    private function createSignedRequest(string $path, array $params): Request
    {
        return new Request(SignatureFactory::create(self::SIGN_KEY)->addSignature($path, $params));
    }
}
