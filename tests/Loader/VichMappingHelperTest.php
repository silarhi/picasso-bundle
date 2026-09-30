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

use Metadata\Driver\DriverChain;
use Metadata\MetadataFactory;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Exception\InvalidImageReferenceException;
use Silarhi\PicassoBundle\Loader\VichMappingHelper;
use Silarhi\PicassoBundle\Tests\Fixtures\Entity\GalleryEntity;
use Silarhi\PicassoBundle\Tests\Fixtures\Entity\MinimalEntity;
use Silarhi\PicassoBundle\Tests\Fixtures\Entity\MultiFieldEntity;
use Silarhi\PicassoBundle\Tests\Fixtures\Entity\ProductEntity;
use stdClass;
use Vich\UploaderBundle\Exception\NotUploadableException;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;
use Vich\UploaderBundle\Mapping\PropertyMappingResolver;
use Vich\UploaderBundle\Metadata\Driver\AttributeDriver;
use Vich\UploaderBundle\Metadata\Driver\AttributeReader;
use Vich\UploaderBundle\Metadata\MetadataReader;

/**
 * Tests VichMappingHelper using a real PropertyMappingFactory
 * backed by real entity classes with VichUploader attributes.
 */
class VichMappingHelperTest extends TestCase
{
    private VichMappingHelper $helper;

    protected function setUp(): void
    {
        $attributeReader = new AttributeReader();
        $attributeDriver = new AttributeDriver($attributeReader, []);
        $driverChain = new DriverChain([$attributeDriver]);
        $metadataFactory = new MetadataFactory($driverChain, \Metadata\ClassHierarchyMetadata::class, false);
        $metadataReader = new MetadataReader($metadataFactory);

        $resolver = new PropertyMappingResolver(
            [],
            [],
            [
                'product_image' => [
                    'upload_destination' => 'products.storage',
                    'uri_prefix' => '/uploads/products',
                    'namer' => null,
                    'directory_namer' => null,
                ],
                'avatar_image' => [
                    'upload_destination' => 'avatars.storage',
                    'uri_prefix' => '/uploads/avatars',
                    'namer' => null,
                    'directory_namer' => null,
                ],
            ],
        );

        $factory = new PropertyMappingFactory($metadataReader, $resolver);
        $this->helper = new VichMappingHelper($factory);
    }

    public function testResolveFieldFindsTheOnlyFieldUsingTheMapping(): void
    {
        self::assertSame('imageFile', $this->helper->resolveField(new ProductEntity(), 'product_image', null));
        self::assertSame('avatarFile', $this->helper->resolveField(new MultiFieldEntity(), 'avatar_image', null));
        self::assertSame('imageFile', $this->helper->resolveField(new MultiFieldEntity(), 'product_image', null));
    }

    public function testResolveFieldAcceptsAFieldUsingTheMapping(): void
    {
        self::assertSame('avatarFile', $this->helper->resolveField(new MultiFieldEntity(), 'avatar_image', 'avatarFile'));
        self::assertSame('thumbnailFile', $this->helper->resolveField(new GalleryEntity(), 'product_image', 'thumbnailFile'));
    }

    public function testResolveFieldRejectsAFieldUsingAnotherMapping(): void
    {
        $this->expectException(InvalidImageReferenceException::class);
        $this->expectExceptionMessage('"Silarhi\PicassoBundle\Tests\Fixtures\Entity\MultiFieldEntity::$avatarFile" uses the VichUploader mapping "avatar_image", but this loader serves the mapping "product_image". Use the loader configured for "avatar_image".');

        $this->helper->resolveField(new MultiFieldEntity(), 'product_image', 'avatarFile');
    }

    public function testResolveFieldRejectsAnUnknownField(): void
    {
        $this->expectException(InvalidImageReferenceException::class);
        $this->expectExceptionMessage('::$imageName" is not a VichUploader upload field.');

        $this->helper->resolveField(new ProductEntity(), 'product_image', 'imageName');
    }

    public function testResolveFieldRejectsAnEntityWithoutTheMapping(): void
    {
        $this->expectException(InvalidImageReferenceException::class);
        $this->expectExceptionMessage('ProductEntity" has no VichUploader field using the mapping "avatar_image".');

        $this->helper->resolveField(new ProductEntity(), 'avatar_image', null);
    }

    public function testResolveFieldAsksForAFieldWhenSeveralUseTheMapping(): void
    {
        $this->expectException(InvalidImageReferenceException::class);
        $this->expectExceptionMessage('GalleryEntity" has several fields using the VichUploader mapping "product_image" (coverFile, thumbnailFile). Pass the one to use as the "field" context key.');

        $this->helper->resolveField(new GalleryEntity(), 'product_image', null);
    }

    public function testResolveFieldRejectsANonUploadableClass(): void
    {
        try {
            $this->helper->resolveField(new stdClass(), 'product_image', null);
            self::fail('A non-uploadable class must be rejected.');
        } catch (InvalidImageReferenceException $e) {
            self::assertSame('"stdClass" is not a VichUploader uploadable class.', $e->getMessage());
            self::assertInstanceOf(NotUploadableException::class, $e->getPrevious());
        }
    }

    public function testReadMimeTypeReturnsStringValue(): void
    {
        $entity = new ProductEntity();
        $entity->mimeType = 'image/jpeg';

        self::assertSame('image/jpeg', $this->helper->readMimeType($entity, 'imageFile'));
    }

    public function testReadMimeTypeReturnsNullWhenNotString(): void
    {
        $entity = new ProductEntity();
        $entity->mimeType = 123;

        self::assertNull($this->helper->readMimeType($entity, 'imageFile'));
    }

    public function testReadMimeTypeReturnsNullWhenPropertyNotSet(): void
    {
        $entity = new ProductEntity();

        self::assertNull($this->helper->readMimeType($entity, 'imageFile'));
    }

    public function testReadDimensionsReturnsIntTuple(): void
    {
        $entity = new ProductEntity();
        $entity->dimensions = [1920, 1080];

        self::assertSame([1920, 1080], $this->helper->readDimensions($entity, 'imageFile'));
    }

    public function testReadDimensionsCastsToInt(): void
    {
        $entity = new ProductEntity();
        $entity->dimensions = ['800', '600'];

        self::assertSame([800, 600], $this->helper->readDimensions($entity, 'imageFile'));
    }

    public function testReadDimensionsReturnsNullForNonArrayValue(): void
    {
        $entity = new ProductEntity();
        $entity->dimensions = 'not-an-array';

        self::assertNull($this->helper->readDimensions($entity, 'imageFile'));
    }

    public function testReadDimensionsReturnsNullForIncompleteArray(): void
    {
        $entity = new ProductEntity();
        $entity->dimensions = [1920];

        self::assertNull($this->helper->readDimensions($entity, 'imageFile'));
    }

    public function testReadDimensionsReturnsNullForNonNumericValues(): void
    {
        $entity = new ProductEntity();
        $entity->dimensions = ['abc', 'def'];

        self::assertNull($this->helper->readDimensions($entity, 'imageFile'));
    }

    public function testReadDimensionsReturnsNullWhenPropertyNotSet(): void
    {
        $entity = new ProductEntity();

        self::assertNull($this->helper->readDimensions($entity, 'imageFile'));
    }

    public function testEntityWithoutMimeTypeMapping(): void
    {
        $entity = new MinimalEntity();

        self::assertNull($this->helper->readMimeType($entity, 'imageFile'));
    }

    public function testEntityWithoutDimensionsMapping(): void
    {
        $entity = new MinimalEntity();

        self::assertNull($this->helper->readDimensions($entity, 'imageFile'));
    }

    public function testReadMimeTypeReturnsNullForUnknownField(): void
    {
        $entity = new ProductEntity();

        self::assertNull($this->helper->readMimeType($entity, 'nonExistentField'));
    }

    public function testReadDimensionsReturnsNullForUnknownField(): void
    {
        $entity = new ProductEntity();

        self::assertNull($this->helper->readDimensions($entity, 'nonExistentField'));
    }
}
