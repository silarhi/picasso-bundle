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
use function in_array;
use function is_string;

use JsonException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Glide\Filesystem\FilesystemException;
use League\Glide\Signatures\SignatureFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silarhi\PicassoBundle\Exception\EncryptionException;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
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
use Symfony\Component\HttpFoundation\Response;
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

    public function testServeReadsSourceFromFilesystemOperator(): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache');

        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('getSource')->willReturn(new Filesystem(new LocalFilesystemAdapter(__DIR__ . '/../Fixtures')));

        $response = $transformer->serve(
            $loader,
            'photo.jpg',
            $this->createSignedRequest('photo.jpg', ['w' => '10', 'fm' => 'webp']),
            ['transformer' => 'glide', 'loader' => 'flysystem'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
    }

    public function testServeRethrowsUnexpectedErrorsUnchanged(): void
    {
        // Not a Flysystem exception, so Glide lets it through untouched
        $failure = new RuntimeException('Storage backend is unreachable.');
        $source = self::createStub(FilesystemOperator::class);
        $source->method('fileExists')->willThrowException($failure);

        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('getSource')->willReturn($source);

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

    /**
     * @return iterable<string, array{bool, string, class-string}>
     */
    public static function invalidMetadataProvider(): iterable
    {
        $notJson = (new UrlEncryption(self::SIGN_KEY))->encrypt('not-json');
        $foreignKey = (new UrlEncryption('another-secret-key'))->encrypt('{"field":"image"}');

        yield 'standard: not encrypted' => [false, 'plain-text', EncryptionException::class];
        yield 'standard: encrypted with another key' => [false, $foreignKey, EncryptionException::class];
        yield 'standard: encrypted non-JSON' => [false, $notJson, JsonException::class];
        // In public-cache mode, a query-string URL is a legacy one: its metadata is decoded to build the redirect
        yield 'legacy redirect: not encrypted' => [true, 'plain-text', EncryptionException::class];
        yield 'legacy redirect: encrypted non-JSON' => [true, $notJson, JsonException::class];
    }

    /**
     * @param class-string $expectedPrevious
     */
    #[DataProvider('invalidMetadataProvider')]
    public function testServeThrowsImageNotFoundOnInvalidMetadata(bool $publicCache, string $metadata, string $expectedPrevious): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: $publicCache);

        try {
            // Signed with the tampered metadata: only the metadata itself is wrong
            $transformer->serve(
                $this->createLoader(__DIR__ . '/../Fixtures'),
                'photo.jpg',
                $this->createSignedRequest('photo.jpg', ['w' => '10', 'fm' => 'webp', '_metadata' => $metadata]),
                ['transformer' => 'glide', 'loader' => 'vich'],
            );
            self::fail('Invalid metadata should have been rejected.');
        } catch (ImageNotFoundException $e) {
            self::assertSame('Invalid metadata parameter.', $e->getMessage());
            self::assertInstanceOf($expectedPrevious, $e->getPrevious());
        }
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

    public function testServeLegacyRedirectKeepsEncryptedMetadata(): void
    {
        $encryption = new UrlEncryption(self::SIGN_KEY);
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true);

        $response = $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            'photo.jpg',
            $this->createSignedRequest('photo.jpg', [
                'w' => '10',
                'fm' => 'webp',
                '_metadata' => $encryption->encrypt('{"class":"App\\\\Entity\\\\Product","field":"image"}'),
            ]),
            ['transformer' => 'glide', 'loader' => 'vich'],
        );

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());

        $location = (string) $response->headers->get('Location');
        self::assertSame('/picasso/glide/vich/photo.jpg/fm_webp%2Cw_10.webp', parse_url($location, \PHP_URL_PATH));

        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame(['_metadata', 's'], array_keys($query), 'Only metadata and signature stay in the query string');
        self::assertIsString($query['_metadata']);
        self::assertSame(
            '{"class":"App\\\\Entity\\\\Product","field":"image"}',
            $encryption->decrypt($query['_metadata']),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function untransformedLegacyPathProvider(): iterable
    {
        // "my" is not a transformation param, so this is an image filename, not a params segment
        yield 'underscore in filename' => ['uploads/my_photo.jpg'];
        yield 'filename without extension' => ['uploads/photo'];
    }

    #[DataProvider('untransformedLegacyPathProvider')]
    public function testServeRedirectsUntransformedLegacyUrl(string $path): void
    {
        $transformer = $this->createTransformer($this->tempDir . '/cache', publicCache: true);

        $response = $transformer->serve(
            $this->createLoader(__DIR__ . '/../Fixtures'),
            $path,
            $this->createSignedRequest($path, []),
            ['transformer' => 'glide', 'loader' => 'filesystem'],
        );

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertStringStartsWith('/picasso/glide/filesystem/' . $path . '/', (string) $response->headers->get('Location'));
    }

    private function createTransformer(string $cache, ?FlysystemRegistry $flysystemRegistry = null, bool $publicCache = false): GlideTransformer
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
            new UrlEncryption(self::SIGN_KEY),
            self::SIGN_KEY,
            $cache,
            'gd',
            null,
            $publicCache,
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
