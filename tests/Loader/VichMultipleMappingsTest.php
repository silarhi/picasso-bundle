<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Loader\VichMappingHelper;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Tests VichUploader with multiple mappings (e.g. entity with avatar + document fields)
 * pointing to different storage directories, verifying encrypted metadata carries
 * the correct source through to the transformer URL.
 */
class VichMultipleMappingsTest extends TestCase
{
    private const SIGN_KEY = 'test-secret-key';

    private VichUploaderLoader $loader;
    private MockObject $storage;
    private MockObject $mappingHelper;
    private UrlEncryption $encryption;

    protected function setUp(): void
    {
        if (!interface_exists(StorageInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->storage = $this->createMock(StorageInterface::class);
        $this->mappingHelper = $this->createMock(VichMappingHelper::class);
        $this->loader = new VichUploaderLoader($this->storage, $this->mappingHelper);
        $this->encryption = new UrlEncryption(self::SIGN_KEY);
    }

    public function testTwoFieldsSameEntityDifferentDestinations(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturnMap([
                [$entity, 'avatarFile', 'avatarFile'],
                [$entity, 'coverFile', 'coverFile'],
            ]);

        $this->mappingHelper->method('getUploadDestination')
            ->willReturnMap([
                [$entity, 'avatarFile', '/var/uploads/avatars'],
                [$entity, 'coverFile', '/var/uploads/covers'],
            ]);

        $this->storage->method('resolvePath')
            ->willReturnMap([
                [$entity, 'avatarFile', null, true, 'users/42/avatar.jpg'],
                [$entity, 'coverFile', null, true, 'users/42/cover.png'],
            ]);

        // Load avatar
        $avatarImage = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));

        self::assertSame('users/42/avatar.jpg', $avatarImage->path);
        self::assertSame('/var/uploads/avatars', $avatarImage->metadata['_source']);

        // Load cover
        $coverImage = $this->loader->load(new ImageReference('cover.png', [
            'entity' => $entity,
            'field' => 'coverFile',
        ]));

        self::assertSame('users/42/cover.png', $coverImage->path);
        self::assertSame('/var/uploads/covers', $coverImage->metadata['_source']);

        // Verify the two sources are different
        self::assertNotSame($avatarImage->metadata['_source'], $coverImage->metadata['_source']);
    }

    public function testEncryptedSourcesAreDistinctPerMapping(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturnMap([
                [$entity, 'avatarFile', 'avatarFile'],
                [$entity, 'coverFile', 'coverFile'],
            ]);

        $this->mappingHelper->method('getUploadDestination')
            ->willReturnMap([
                [$entity, 'avatarFile', '/var/uploads/avatars'],
                [$entity, 'coverFile', '/var/uploads/covers'],
            ]);

        $this->storage->method('resolvePath')
            ->willReturnMap([
                [$entity, 'avatarFile', null, true, 'avatar.jpg'],
                [$entity, 'coverFile', null, true, 'cover.png'],
            ]);

        $avatarImage = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));

        $coverImage = $this->loader->load(new ImageReference('cover.png', [
            'entity' => $entity,
            'field' => 'coverFile',
        ]));

        // Encrypt both sources
        $avatarEncrypted = $this->encryption->encrypt($avatarImage->metadata['_source']);
        $coverEncrypted = $this->encryption->encrypt($coverImage->metadata['_source']);

        // They should decrypt to different values
        self::assertSame('/var/uploads/avatars', $this->encryption->decrypt($avatarEncrypted));
        self::assertSame('/var/uploads/covers', $this->encryption->decrypt($coverEncrypted));
    }

    public function testGlideTransformerUrlCarriesCorrectEncryptedSource(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturnMap([
                [$entity, 'avatarFile', 'avatarFile'],
                [$entity, 'documentFile', 'documentFile'],
            ]);

        $this->mappingHelper->method('getUploadDestination')
            ->willReturnMap([
                [$entity, 'avatarFile', '/var/uploads/avatars'],
                [$entity, 'documentFile', '/var/uploads/documents'],
            ]);

        $this->storage->method('resolvePath')
            ->willReturnMap([
                [$entity, 'avatarFile', null, true, 'user/avatar.jpg'],
                [$entity, 'documentFile', null, true, 'docs/report.pdf'],
            ]);

        $transformer = $this->createGlideTransformer();

        // Load both images
        $avatarImage = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));

        $documentImage = $this->loader->load(new ImageReference('report.pdf', [
            'entity' => $entity,
            'field' => 'documentFile',
        ]));

        // Generate URLs
        $avatarUrl = $transformer->url($avatarImage, new ImageTransformation(width: 100), ['loader' => 'vich']);
        $documentUrl = $transformer->url($documentImage, new ImageTransformation(width: 800), ['loader' => 'vich']);

        // Both URLs should contain _source
        self::assertStringContainsString('_source=', $avatarUrl);
        self::assertStringContainsString('_source=', $documentUrl);

        // Extract and decrypt _source from each URL
        parse_str(parse_url($avatarUrl, \PHP_URL_QUERY) ?? '', $avatarQuery);
        parse_str(parse_url($documentUrl, \PHP_URL_QUERY) ?? '', $documentQuery);

        self::assertSame('/var/uploads/avatars', $this->encryption->decrypt($avatarQuery['_source']));
        self::assertSame('/var/uploads/documents', $this->encryption->decrypt($documentQuery['_source']));
    }

    public function testFilesystemSourceAsUploadDestination(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, 'imageFile')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->with($entity, 'imageFile')
            ->willReturn('/var/www/app/public/uploads/images');

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('2024/photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        // Plain filesystem path as source
        self::assertSame('/var/www/app/public/uploads/images', $image->metadata['_source']);
        self::assertIsString($image->metadata['_source']);

        // Verify round-trip through encryption
        $encrypted = $this->encryption->encrypt($image->metadata['_source']);
        self::assertSame('/var/www/app/public/uploads/images', $this->encryption->decrypt($encrypted));
    }

    public function testFlysystemServiceIdAsUploadDestination(): void
    {
        $entity = new \stdClass();

        // Flysystem-backed storage returns a service-like identifier
        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, 'documentFile')
            ->willReturn('documentFile');

        $this->mappingHelper->method('getUploadDestination')
            ->with($entity, 'documentFile')
            ->willReturn('default.storage');

        $this->storage->method('resolvePath')
            ->with($entity, 'documentFile', null, true)
            ->willReturn('contracts/2024/agreement.pdf');

        $image = $this->loader->load(new ImageReference('agreement.pdf', [
            'entity' => $entity,
            'field' => 'documentFile',
        ]));

        // Flysystem adapter name as source
        self::assertSame('default.storage', $image->metadata['_source']);

        // Verify round-trip through encryption
        $encrypted = $this->encryption->encrypt($image->metadata['_source']);
        self::assertSame('default.storage', $this->encryption->decrypt($encrypted));
    }

    public function testMixedStorageTypesInSameEntity(): void
    {
        $entity = new \stdClass();

        // avatarFile → plain filesystem path
        // documentFile → Flysystem adapter
        $this->mappingHelper->method('getFilePropertyName')
            ->willReturnMap([
                [$entity, 'avatarFile', 'avatarFile'],
                [$entity, 'documentFile', 'documentFile'],
            ]);

        $this->mappingHelper->method('getUploadDestination')
            ->willReturnMap([
                [$entity, 'avatarFile', '/var/uploads/avatars'],
                [$entity, 'documentFile', 'documents.storage'],
            ]);

        $this->storage->method('resolvePath')
            ->willReturnMap([
                [$entity, 'avatarFile', null, true, 'users/42/avatar.jpg'],
                [$entity, 'documentFile', null, true, 'contracts/agreement.pdf'],
            ]);

        $transformer = $this->createGlideTransformer();

        // Load and generate URL for filesystem-backed image
        $avatarImage = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));
        $avatarUrl = $transformer->url($avatarImage, new ImageTransformation(width: 200), ['loader' => 'vich']);

        // Load and generate URL for Flysystem-backed image
        $documentImage = $this->loader->load(new ImageReference('agreement.pdf', [
            'entity' => $entity,
            'field' => 'documentFile',
        ]));
        $documentUrl = $transformer->url($documentImage, new ImageTransformation(width: 800), ['loader' => 'vich']);

        // Decrypt and verify each source type
        parse_str(parse_url($avatarUrl, \PHP_URL_QUERY) ?? '', $avatarQuery);
        parse_str(parse_url($documentUrl, \PHP_URL_QUERY) ?? '', $documentQuery);

        // Filesystem source
        self::assertSame('/var/uploads/avatars', $this->encryption->decrypt($avatarQuery['_source']));
        // Flysystem source
        self::assertSame('documents.storage', $this->encryption->decrypt($documentQuery['_source']));
    }

    public function testAutoDetectedFieldWithMultipleMappings(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, null)
            ->willReturn('avatarFile');

        $this->mappingHelper->method('getUploadDestination')
            ->with($entity, null)
            ->willReturn('/var/uploads/avatars');

        $this->storage->method('resolvePath')
            ->with($entity, 'avatarFile', null, true)
            ->willReturn('users/42/avatar.jpg');

        $image = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
        ]));

        self::assertSame('users/42/avatar.jpg', $image->path);
        self::assertSame('/var/uploads/avatars', $image->metadata['_source']);
    }

    public function testDimensionsAndMetadataCoexist(): void
    {
        $entity = new class {
            public function getAvatarFileDimensions(): array
            {
                return [200, 200];
            }
        };

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('avatarFile');

        $this->mappingHelper->method('getUploadDestination')
            ->willReturn('/var/uploads/avatars');

        $this->storage->method('resolvePath')->willReturn('avatar.jpg');

        $image = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));

        self::assertSame(200, $image->width);
        self::assertSame(200, $image->height);
        self::assertSame('/var/uploads/avatars', $image->metadata['_source']);
    }

    public function testWithoutMetadataStillCarriesSource(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->willReturn('/var/uploads/images');

        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]), withMetadata: false);

        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertSame('/var/uploads/images', $image->metadata['_source']);
    }

    private function createGlideTransformer(): GlideTransformer
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')
            ->willReturnCallback(static fn (string $name, array $params): string => '/picasso/'.$params['transformer'].'/'.$params['loader'].'/'.$params['path'].'?'.http_build_query(
                array_filter($params, static fn ($k): bool => !\in_array($k, ['transformer', 'loader', 'path'], true), \ARRAY_FILTER_USE_KEY),
            ));

        return new GlideTransformer($router, $this->encryption, self::SIGN_KEY, '/tmp/cache');
    }
}
