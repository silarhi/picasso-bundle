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
use Silarhi\PicassoBundle\Loader\VichMappingHelper;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Attribute\Uploadable;
use Vich\UploaderBundle\Mapping\Attribute\UploadableField;
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
        $metadataFactory = new MetadataFactory($driverChain, 'Metadata\ClassHierarchyMetadata', false);
        $metadataReader = new MetadataReader($metadataFactory);

        $resolver = new PropertyMappingResolver(
            [],
            [],
            [
                'product_image' => [
                    'upload_destination' => '/var/uploads/products',
                    'uri_prefix' => '/uploads/products',
                    'namer' => null,
                    'directory_namer' => null,
                ],
                'avatar_image' => [
                    'upload_destination' => '/var/uploads/avatars',
                    'uri_prefix' => '/uploads/avatars',
                    'namer' => null,
                    'directory_namer' => null,
                ],
            ],
        );

        $factory = new PropertyMappingFactory($metadataReader, $resolver);
        $this->helper = new VichMappingHelper($factory);
    }

    public function testGetFilePropertyNameWithExplicitField(): void
    {
        $entity = new ProductEntity();

        self::assertSame('imageFile', $this->helper->getFilePropertyName($entity, 'imageFile'));
    }

    public function testGetFilePropertyNameWithNullFieldAutoDetects(): void
    {
        $entity = new ProductEntity();

        self::assertSame('imageFile', $this->helper->getFilePropertyName($entity, null));
    }

    public function testGetUploadDestinationWithExplicitField(): void
    {
        $entity = new ProductEntity();

        self::assertSame('/var/uploads/products', $this->helper->getUploadDestination($entity, 'imageFile'));
    }

    public function testGetUploadDestinationWithNullFieldAutoDetects(): void
    {
        $entity = new ProductEntity();

        self::assertSame('/var/uploads/products', $this->helper->getUploadDestination($entity, null));
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

    public function testReadMimeTypeWithNullFieldUsesAutoDetect(): void
    {
        $entity = new ProductEntity();
        $entity->mimeType = 'image/png';

        self::assertSame('image/png', $this->helper->readMimeType($entity, null));
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

    public function testReadDimensionsWithNullFieldUsesAutoDetect(): void
    {
        $entity = new ProductEntity();
        $entity->dimensions = [640, 480];

        self::assertSame([640, 480], $this->helper->readDimensions($entity, null));
    }

    public function testMultiFieldEntityWithExplicitField(): void
    {
        $entity = new MultiFieldEntity();

        self::assertSame('avatarFile', $this->helper->getFilePropertyName($entity, 'avatarFile'));
        self::assertSame('/var/uploads/avatars', $this->helper->getUploadDestination($entity, 'avatarFile'));
    }

    public function testMultiFieldEntityAutoDetectsFirstMapping(): void
    {
        $entity = new MultiFieldEntity();

        $propertyName = $this->helper->getFilePropertyName($entity, null);
        self::assertNotNull($propertyName);
        self::assertContains($propertyName, ['imageFile', 'avatarFile']);
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
}

#[Uploadable]
class ProductEntity
{
    #[UploadableField(
        mapping: 'product_image',
        fileNameProperty: 'imageName',
        mimeType: 'mimeType',
        dimensions: 'dimensions',
    )]
    public ?File $imageFile = null;

    public ?string $imageName = null;

    public mixed $mimeType = null;

    public mixed $dimensions = null;
}

#[Uploadable]
class MultiFieldEntity
{
    #[UploadableField(
        mapping: 'product_image',
        fileNameProperty: 'imageName',
        mimeType: 'mimeType',
        dimensions: 'dimensions',
    )]
    public ?File $imageFile = null;

    public ?string $imageName = null;

    #[UploadableField(
        mapping: 'avatar_image',
        fileNameProperty: 'avatarName',
    )]
    public ?File $avatarFile = null;

    public ?string $avatarName = null;

    public mixed $mimeType = null;

    public mixed $dimensions = null;
}

#[Uploadable]
class MinimalEntity
{
    #[UploadableField(
        mapping: 'product_image',
        fileNameProperty: 'imageName',
    )]
    public ?File $imageFile = null;

    public ?string $imageName = null;
}
