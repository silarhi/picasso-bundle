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

namespace Silarhi\PicassoBundle\Tests\Loader;

use function assert;
use function in_array;
use function is_string;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\VichMappingHelperInterface;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use stdClass;
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
    private MockObject&StorageInterface $storage;
    private MockObject&VichMappingHelperInterface $mappingHelper;
    private UrlEncryption $encryption;

    protected function setUp(): void
    {
        if (!interface_exists(StorageInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->storage = $this->createMock(StorageInterface::class);
        $this->mappingHelper = $this->createMock(VichMappingHelperInterface::class);
        $storageContainer = $this->createMock(ContainerInterface::class);
        $flysystemRegistry = new FlysystemRegistry($storageContainer);
        $this->loader = new VichUploaderLoader($this->storage, $this->mappingHelper, $flysystemRegistry);
        $this->encryption = new UrlEncryption(self::SIGN_KEY);
    }

    public function testTwoFieldsSameEntityDifferentDestinations(): void
    {
        $entity = new stdClass();

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

        $this->storage->method('resolveStream')->willReturn(null);

        // Load avatar
        $avatarImage = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));

        self::assertSame('users/42/avatar.jpg', $avatarImage->path);
        self::assertSame('/var/uploads/avatars', $avatarImage->metadata['upload_destination']);

        // Load cover
        $coverImage = $this->loader->load(new ImageReference('cover.png', [
            'entity' => $entity,
            'field' => 'coverFile',
        ]));

        self::assertSame('users/42/cover.png', $coverImage->path);
        self::assertSame('/var/uploads/covers', $coverImage->metadata['upload_destination']);

        // Verify the two sources are different
        self::assertNotSame($avatarImage->metadata['upload_destination'], $coverImage->metadata['upload_destination']);
    }

    public function testEncryptedSourcesAreDistinctPerMapping(): void
    {
        $entity = new stdClass();

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

        $this->storage->method('resolveStream')->willReturn(null);

        $avatarImage = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));

        $coverImage = $this->loader->load(new ImageReference('cover.png', [
            'entity' => $entity,
            'field' => 'coverFile',
        ]));

        // Encrypt both sources
        $avatarSource = $avatarImage->metadata['upload_destination'];
        $coverSource = $coverImage->metadata['upload_destination'];
        self::assertIsString($avatarSource);
        self::assertIsString($coverSource);
        $avatarEncrypted = $this->encryption->encrypt($avatarSource);
        $coverEncrypted = $this->encryption->encrypt($coverSource);

        // They should decrypt to different values
        self::assertSame('/var/uploads/avatars', $this->encryption->decrypt($avatarEncrypted));
        self::assertSame('/var/uploads/covers', $this->encryption->decrypt($coverEncrypted));
    }

    public function testGlideTransformerUrlCarriesCorrectEncryptedSource(): void
    {
        $entity = new stdClass();

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

        $this->storage->method('resolveStream')->willReturn(null);

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

        // Both URLs should contain _metadata
        self::assertStringContainsString('_metadata=', $avatarUrl);
        self::assertStringContainsString('_metadata=', $documentUrl);

        // Extract and decrypt _metadata from each URL
        $avatarQs = parse_url($avatarUrl, \PHP_URL_QUERY);
        $documentQs = parse_url($documentUrl, \PHP_URL_QUERY);
        self::assertIsString($avatarQs);
        self::assertIsString($documentQs);
        parse_str($avatarQs, $avatarQuery);
        parse_str($documentQs, $documentQuery);

        self::assertIsString($avatarQuery['_metadata']);
        self::assertIsString($documentQuery['_metadata']);
        /** @var array<string, mixed> $avatarMeta */
        $avatarMeta = json_decode($this->encryption->decrypt($avatarQuery['_metadata']), true, flags: \JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $documentMeta */
        $documentMeta = json_decode($this->encryption->decrypt($documentQuery['_metadata']), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('/var/uploads/avatars', $avatarMeta['upload_destination']);
        self::assertSame('/var/uploads/documents', $documentMeta['upload_destination']);
    }

    public function testFilesystemSourceAsUploadDestination(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, 'imageFile')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->with($entity, 'imageFile')
            ->willReturn('/var/www/app/public/uploads/images');

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('2024/photo.jpg');

        $this->storage->method('resolveStream')->willReturn(null);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        // Plain filesystem path as source
        self::assertSame('/var/www/app/public/uploads/images', $image->metadata['upload_destination']);
        self::assertIsString($image->metadata['upload_destination']);

        // Verify round-trip through encryption
        $encrypted = $this->encryption->encrypt($image->metadata['upload_destination']);
        self::assertSame('/var/www/app/public/uploads/images', $this->encryption->decrypt($encrypted));
    }

    public function testFlysystemServiceIdAsUploadDestination(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, 'documentFile')
            ->willReturn('documentFile');

        $this->mappingHelper->method('getUploadDestination')
            ->with($entity, 'documentFile')
            ->willReturn('default.storage');

        $this->storage->method('resolvePath')
            ->with($entity, 'documentFile', null, true)
            ->willReturn('contracts/2024/agreement.pdf');

        $this->storage->method('resolveStream')->willReturn(null);

        $image = $this->loader->load(new ImageReference('agreement.pdf', [
            'entity' => $entity,
            'field' => 'documentFile',
        ]));

        self::assertSame('default.storage', $image->metadata['upload_destination']);

        // Verify round-trip through encryption
        $encrypted = $this->encryption->encrypt($image->metadata['upload_destination']);
        self::assertSame('default.storage', $this->encryption->decrypt($encrypted));
    }

    public function testMixedStorageTypesInSameEntity(): void
    {
        $entity = new stdClass();

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

        $this->storage->method('resolveStream')->willReturn(null);

        $transformer = $this->createGlideTransformer();

        $avatarImage = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));
        $avatarUrl = $transformer->url($avatarImage, new ImageTransformation(width: 200), ['loader' => 'vich']);

        $documentImage = $this->loader->load(new ImageReference('agreement.pdf', [
            'entity' => $entity,
            'field' => 'documentFile',
        ]));
        $documentUrl = $transformer->url($documentImage, new ImageTransformation(width: 800), ['loader' => 'vich']);

        $avatarQs = parse_url($avatarUrl, \PHP_URL_QUERY);
        $documentQs = parse_url($documentUrl, \PHP_URL_QUERY);
        self::assertIsString($avatarQs);
        self::assertIsString($documentQs);
        parse_str($avatarQs, $avatarQuery);
        parse_str($documentQs, $documentQuery);

        self::assertIsString($avatarQuery['_metadata']);
        self::assertIsString($documentQuery['_metadata']);
        /** @var array<string, mixed> $avatarMeta */
        $avatarMeta = json_decode($this->encryption->decrypt($avatarQuery['_metadata']), true, flags: \JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $documentMeta */
        $documentMeta = json_decode($this->encryption->decrypt($documentQuery['_metadata']), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('/var/uploads/avatars', $avatarMeta['upload_destination']);
        self::assertSame('documents.storage', $documentMeta['upload_destination']);
    }

    public function testAutoDetectedFieldWithMultipleMappings(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, null)
            ->willReturn('avatarFile');

        $this->mappingHelper->method('getUploadDestination')
            ->with($entity, null)
            ->willReturn('/var/uploads/avatars');

        $this->storage->method('resolvePath')
            ->with($entity, 'avatarFile', null, true)
            ->willReturn('users/42/avatar.jpg');

        $this->storage->method('resolveStream')->willReturn(null);

        $image = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
        ]));

        self::assertSame('users/42/avatar.jpg', $image->path);
        self::assertSame('/var/uploads/avatars', $image->metadata['upload_destination']);
    }

    private function createGlideTransformer(): GlideTransformer
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')
            ->willReturnCallback(static function (string $name, array $params): string {
                assert(is_string($params['transformer']));
                assert(is_string($params['loader']));
                assert(is_string($params['path']));

                return '/picasso/' . $params['transformer'] . '/' . $params['loader'] . '/' . $params['path'] . '?' . http_build_query(
                    array_filter($params, static fn ($k): bool => !in_array($k, ['transformer', 'loader', 'path'], true), \ARRAY_FILTER_USE_KEY),
                );
            });

        return new GlideTransformer($router, $this->encryption, self::SIGN_KEY, '/tmp/cache');
    }
}
