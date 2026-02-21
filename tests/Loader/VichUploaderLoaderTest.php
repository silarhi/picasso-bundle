<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\VichMappingHelper;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderLoaderTest extends TestCase
{
    private VichUploaderLoader $loader;
    private StorageInterface $storage;
    private VichMappingHelper $mappingHelper;

    protected function setUp(): void
    {
        if (!interface_exists(StorageInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->storage = $this->createMock(StorageInterface::class);
        $this->mappingHelper = $this->createMock(VichMappingHelper::class);
        $this->loader = new VichUploaderLoader($this->storage, $this->mappingHelper);
    }

    public function testLoadWithStringSource(): void
    {
        $image = $this->loader->load(new ImageReference('/uploads/photo.jpg'));

        self::assertSame('uploads/photo.jpg', $image->path);
        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testLoadWithEntityAndFieldContext(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, 'imageFile')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('2024/february/photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame('2024/february/photo.jpg', $image->path);
    }

    public function testLoadAutoDetectsFieldWhenNull(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, null)
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('auto/detected.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
        ]));

        self::assertSame('auto/detected.jpg', $image->path);
    }

    public function testLoadFallsBackWhenNoMappingFound(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, null)
            ->willReturn(null);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
        ]));

        self::assertSame('photo.jpg', $image->path);
        self::assertNull($image->width);
    }

    public function testLoadWithEntityStripsLeadingSlash(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')
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
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')
            ->willReturn(null);

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame('', $image->path);
    }

    public function testLoadDetectsDimensionsFromGetterMethod(): void
    {
        $entity = new class {
            public function getImageFileDimensions(): array
            {
                return [1920, 1080];
            }
        };

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame(1920, $image->width);
        self::assertSame(1080, $image->height);
    }

    public function testLoadDetectsDimensionsFromEmbeddedFileObject(): void
    {
        $file = new class {
            public function getDimensions(): array
            {
                return [800, 600];
            }
        };

        $entity = new class($file) {
            public function __construct(private readonly object $file)
            {
            }

            public function getImageFile(): object
            {
                return $this->file;
            }
        };

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]));

        self::assertSame(800, $image->width);
        self::assertSame(600, $image->height);
    }

    public function testLoadSkipsDimensionDetectionWhenSourceDimensionsProvided(): void
    {
        $entity = new class {
            public function getImageFileDimensions(): array
            {
                return [1920, 1080];
            }
        };

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
            'sourceWidth' => 500,
            'sourceHeight' => 400,
        ]));

        self::assertSame(500, $image->width);
        self::assertSame(400, $image->height);
    }

    public function testLoadWithoutMetadataSkipsDimensionDetection(): void
    {
        $entity = new class {
            public function getImageFileDimensions(): array
            {
                return [1920, 1080];
            }
        };

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $image = $this->loader->load(new ImageReference('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]), withMetadata: false);

        self::assertSame('photo.jpg', $image->path);
        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testGetSourceThrowsWhenNotConfigured(): void
    {
        $this->expectException(\LogicException::class);
        $this->loader->getSource();
    }

    public function testGetSourceReturnsConfiguredSource(): void
    {
        $loader = new VichUploaderLoader($this->storage, $this->mappingHelper, '/var/uploads');

        self::assertSame('/var/uploads', $loader->getSource());
    }
}
