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

use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\VichMappingHelperInterface;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use stdClass;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderLoaderTest extends TestCase
{
    private VichUploaderLoader $loader;
    private MockObject&StorageInterface $storage;
    private MockObject&VichMappingHelperInterface $mappingHelper;
    private MockObject&MetadataGuesserInterface $metadataGuesser;

    protected function setUp(): void
    {
        if (!interface_exists(StorageInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->storage = $this->createMock(StorageInterface::class);
        $this->mappingHelper = $this->createMock(VichMappingHelperInterface::class);
        $this->metadataGuesser = $this->createMock(MetadataGuesserInterface::class);
        $this->loader = new VichUploaderLoader($this->storage, $this->mappingHelper, $this->metadataGuesser);
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

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, 'imageFile')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->with($entity, 'imageFile')
            ->willReturn('/var/uploads/images');

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('2024/february/photo.jpg');

        $this->storage->method('resolveStream')
            ->willReturn(null);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame('2024/february/photo.jpg', $image->path);
        self::assertSame('/var/uploads/images', $image->metadata['_source']);
    }

    public function testLoadAutoDetectsFieldWhenNull(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, null)
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->with($entity, null)
            ->willReturn('/var/uploads');

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('auto/detected.jpg');

        $this->storage->method('resolveStream')
            ->willReturn(null);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
        ]));

        self::assertSame('auto/detected.jpg', $image->path);
        self::assertSame('/var/uploads', $image->metadata['_source']);
    }

    public function testLoadFallsBackWhenNoMappingFound(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
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

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('/photo.jpg');

        $this->storage->method('resolveStream')
            ->willReturn(null);

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

        $this->storage->method('resolveStream')
            ->willReturn(null);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame('', $image->path);
    }

    public function testLoadProvidesStreamWhenAvailable(): void
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

        self::assertSame($stream, $image->stream);
        self::assertSame('/var/uploads', $image->metadata['_source']);
    }

    public function testLoadHandlesStreamException(): void
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

        self::assertNull($image->stream);
        self::assertSame('/var/uploads', $image->metadata['_source']);
    }

    public function testGetSourceAlwaysThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('encrypted URL metadata');
        $this->loader->getSource();
    }

    public function testLoadWithNullUploadDestinationReturnsEmptyMetadata(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->mappingHelper->method('getUploadDestination')
            ->willReturn(null);

        $this->storage->method('resolvePath')->willReturn('photo.jpg');
        $this->storage->method('resolveStream')->willReturn(null);

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

        $this->storage->method('resolvePath')
            ->with($entity, 'avatarFile', null, true)
            ->willReturn('users/avatar.jpg');

        $this->storage->method('resolveStream')->willReturn(null);

        $image = $this->loader->load(new ImageReference('avatar.jpg', [
            'entity' => $entity,
            'field' => 'avatarFile',
        ]));

        self::assertSame('users/avatar.jpg', $image->path);
        self::assertSame('/var/uploads/avatars', $image->metadata['_source']);
    }

    public function testLoadWithMetadataUsesGuesser(): void
    {
        $entity = new stdClass();
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $this->mappingHelper->method('getFilePropertyName')->willReturn('imageFile');
        $this->mappingHelper->method('getUploadDestination')->willReturn('/var/uploads');
        $this->storage->method('resolvePath')->willReturn('photo.jpg');
        $this->storage->method('resolveStream')->willReturn($stream);

        $this->metadataGuesser->expects(self::once())
            ->method('guess')
            ->with($stream)
            ->willReturn(['width' => 1024, 'height' => 768, 'mimeType' => 'image/jpeg']);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]), withMetadata: true);

        self::assertSame(1024, $image->width);
        self::assertSame(768, $image->height);
        self::assertSame('image/jpeg', $image->mimeType);
    }

    public function testLoadWithMetadataSkipsWhenNoStream(): void
    {
        $entity = new stdClass();

        $this->mappingHelper->method('getFilePropertyName')->willReturn('imageFile');
        $this->mappingHelper->method('getUploadDestination')->willReturn('/var/uploads');
        $this->storage->method('resolvePath')->willReturn('photo.jpg');
        $this->storage->method('resolveStream')->willReturn(null);

        $this->metadataGuesser->expects(self::never())->method('guess');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]), withMetadata: true);

        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testLoadWithoutMetadataSkipsGuesser(): void
    {
        $entity = new stdClass();
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $this->mappingHelper->method('getFilePropertyName')->willReturn('imageFile');
        $this->mappingHelper->method('getUploadDestination')->willReturn('/var/uploads');
        $this->storage->method('resolvePath')->willReturn('photo.jpg');
        $this->storage->method('resolveStream')->willReturn($stream);

        $this->metadataGuesser->expects(self::never())->method('guess');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertNull($image->width);
        self::assertSame($stream, $image->stream);
    }
}
