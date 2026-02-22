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

    protected function setUp(): void
    {
        if (!interface_exists(StorageInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->storage = $this->createMock(StorageInterface::class);
        $this->mappingHelper = $this->createMock(VichMappingHelper::class);
        $this->loader = new VichUploaderLoader($this->storage, $this->mappingHelper);
    }

    public function testTwoFieldsSameEntityDifferentDestinations(): void
    {
        $entity = new \stdClass();

        // Configure mapping helper for two different fields
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
        $avatarEncrypted = UrlEncryption::encrypt($avatarImage->metadata['_source'], self::SIGN_KEY);
        $coverEncrypted = UrlEncryption::encrypt($coverImage->metadata['_source'], self::SIGN_KEY);

        // They should decrypt to different values
        self::assertSame('/var/uploads/avatars', UrlEncryption::decrypt($avatarEncrypted, self::SIGN_KEY));
        self::assertSame('/var/uploads/covers', UrlEncryption::decrypt($coverEncrypted, self::SIGN_KEY));
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

        // Create GlideTransformer with a mock router
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')
            ->willReturnCallback(static fn (string $name, array $params): string => '/picasso/'.$params['transformer'].'/'.$params['loader'].'/'.$params['path'].'?'.http_build_query(
                array_filter($params, static fn ($k): bool => !\in_array($k, ['transformer', 'loader', 'path'], true), \ARRAY_FILTER_USE_KEY),
            ));

        $transformer = new GlideTransformer($router, self::SIGN_KEY, '/tmp/cache');

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

        self::assertSame('/var/uploads/avatars', UrlEncryption::decrypt($avatarQuery['_source'], self::SIGN_KEY));
        self::assertSame('/var/uploads/documents', UrlEncryption::decrypt($documentQuery['_source'], self::SIGN_KEY));
    }

    public function testAutoDetectedFieldWithMultipleMappings(): void
    {
        $entity = new \stdClass();

        // When field is null, auto-detect returns first mapping
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
}
