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

use Closure;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\InvalidMetadataException;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\VichMappingHelperInterface;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use stdClass;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderLoaderTest extends TestCase
{
    private VichUploaderLoader $loader;
    private MockObject&StorageInterface $storage;
    private MockObject&VichMappingHelperInterface $mappingHelper;
    private MockObject&ContainerInterface $storageContainer;
    private FlysystemRegistry $flysystemRegistry;

    protected function setUp(): void
    {
        if (!interface_exists(StorageInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->storage = $this->createMock(StorageInterface::class);
        $this->mappingHelper = $this->createMock(VichMappingHelperInterface::class);
        $this->storageContainer = $this->createMock(ContainerInterface::class);
        $this->flysystemRegistry = new FlysystemRegistry($this->storageContainer);
        $this->loader = new VichUploaderLoader($this->storage, $this->mappingHelper, $this->flysystemRegistry);
    }

    public function testLoadWithStringSource(): void
    {
        $image = $this->loader->load(new ImageReference('/uploads/photo.jpg'));

        self::assertSame('uploads/photo.jpg', $image->path);
        self::assertNull($image->stream);
        self::assertSame([], $image->metadata);
    }

    public function testLoadWithEntityAndFieldContext(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->expects(self::any())->method('getFilePropertyName')
            ->with($entity, 'imageFile')
            ->willReturn('imageFile');

        $this->mappingHelper->expects(self::any())->method('getUploadDestination')
            ->with($entity, 'imageFile')
            ->willReturn('/var/uploads/images');

        $this->storage->expects(self::any())->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('2024/february/photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame('2024/february/photo.jpg', $image->path);
        self::assertSame('/var/uploads/images', $image->metadata['upload_destination']);
    }

    public function testLoadAutoDetectsFieldWhenNull(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->expects(self::any())->method('getFilePropertyName')
            ->with($entity, null)
            ->willReturn('imageFile');

        $this->mappingHelper->expects(self::any())->method('getUploadDestination')
            ->with($entity, null)
            ->willReturn('/var/uploads');

        $this->storage->expects(self::any())->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('auto/detected.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
        ]));

        self::assertSame('auto/detected.jpg', $image->path);
        self::assertSame('/var/uploads', $image->metadata['upload_destination']);
    }

    public function testLoadFallsBackWhenNoMappingFound(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->expects(self::any())->method('getFilePropertyName')
            ->with($entity, null)
            ->willReturn(null);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
        ]));

        self::assertSame('photo.jpg', $image->path);
    }

    public function testLoadWithEntityStripsLeadingSlash(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->willReturn(null);

        $this->storage->expects(self::any())->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('/photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame('photo.jpg', $image->path);
    }

    public function testLoadWithEntityReturnsEmptyOnNull(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->willReturn(null);

        $this->storage->method('resolvePath')
            ->willReturn(null);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame('', $image->path);
    }

    public function testLoadProvidesLazyStream(): void
    {
        $entity = new stdClass();
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->willReturn('/var/uploads');

        $this->storage->method('resolvePath')->willReturn('photo.jpg');
        $this->storage->method('resolveStream')->willReturn($stream);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertInstanceOf(Closure::class, $image->stream);
        self::assertSame($stream, ($image->stream)());
        self::assertSame('/var/uploads', $image->metadata['upload_destination']);
    }

    public function testLazyStreamReturnsNullOnException(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->willReturn('/var/uploads');

        $this->storage->method('resolvePath')->willReturn('photo.jpg');
        $this->storage->method('resolveStream')
            ->willThrowException(new RuntimeException('Stream not available'));

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertInstanceOf(Closure::class, $image->stream);
        self::assertNull($image->resolveStream());
        self::assertSame('/var/uploads', $image->metadata['upload_destination']);
    }

    public function testGetSourceThrowsWithoutUploadDestination(): void
    {
        $this->expectException(InvalidMetadataException::class);
        $this->expectExceptionMessage('Upload destination is required');
        $this->loader->getSource([]);
    }

    public function testGetSourceReturnsFlysystemStorageWhenRegistered(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);

        $this->storageContainer->expects(self::any())->method('has')
            ->with('uploads.storage.public')
            ->willReturn(true);

        $this->storageContainer->expects(self::any())->method('get')
            ->with('uploads.storage.public')
            ->willReturn($filesystem);

        $source = $this->loader->getSource(['upload_destination' => 'uploads.storage.public']);

        self::assertSame($filesystem, $source);
    }

    public function testGetSourceReturnsStringWhenNotInRegistry(): void
    {
        $this->storageContainer->expects(self::any())->method('has')
            ->with('/var/uploads/images')
            ->willReturn(false);

        $source = $this->loader->getSource(['upload_destination' => '/var/uploads/images']);

        self::assertSame('/var/uploads/images', $source);
    }

    public function testLoadWithNullUploadDestinationReturnsEmptyMetadata(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->willReturn(null);

        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame([], $image->metadata);
    }

    public function testLoadWithMultipleMappingsUsesCorrectDestination(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->expects(self::once())
            ->method('getFilePropertyName')
            ->with($entity, 'avatarFile')
            ->willReturn('avatarFile');

        $this->mappingHelper->expects(self::once())
            ->method('getUploadDestination')
            ->with($entity, 'avatarFile')
            ->willReturn('/var/uploads/avatars');

        $this->storage->expects(self::any())->method('resolvePath')
            ->with($entity, 'avatarFile', null, true)
            ->willReturn('users/avatar.jpg');

        $image = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));

        self::assertSame('users/avatar.jpg', $image->path);
        self::assertSame('/var/uploads/avatars', $image->metadata['upload_destination']);
    }

    public function testLoadWithMetadataReadsDimensionsAndMimeType(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')->willReturn('imageFile');
        $this->mappingHelper->method('getUploadDestination')->willReturn('/var/uploads');
        $this->mappingHelper->expects(self::any())->method('readDimensions')
            ->with($entity, 'imageFile')
            ->willReturn([1024, 768]);
        $this->mappingHelper->expects(self::any())->method('readMimeType')
            ->with($entity, 'imageFile')
            ->willReturn('image/jpeg');
        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]), withMetadata: true);

        self::assertSame(1024, $image->width);
        self::assertSame(768, $image->height);
        self::assertSame('image/jpeg', $image->mimeType);
    }

    public function testLoadWithMetadataReturnsNullWhenAttributesNotConfigured(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')->willReturn('imageFile');
        $this->mappingHelper->method('getUploadDestination')->willReturn('/var/uploads');
        $this->mappingHelper->method('readDimensions')->willReturn(null);
        $this->mappingHelper->method('readMimeType')->willReturn(null);
        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]), withMetadata: true);

        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->mimeType);
    }

    public function testLoadWithoutMetadataSkipsDimensionReading(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')->willReturn('imageFile');
        $this->mappingHelper->method('getUploadDestination')->willReturn('/var/uploads');
        $this->mappingHelper->expects(self::never())->method('readDimensions');
        $this->mappingHelper->expects(self::never())->method('readMimeType');
        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertNull($image->width);
        self::assertNull($image->height);
    }
}
