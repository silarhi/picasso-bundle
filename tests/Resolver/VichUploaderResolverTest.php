<?php

namespace Silarhi\PicassoBundle\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Resolver\VichMappingHelper;
use Silarhi\PicassoBundle\Resolver\VichUploaderResolver;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderResolverTest extends TestCase
{
    private VichUploaderResolver $resolver;
    private StorageInterface $storage;
    private VichMappingHelper $mappingHelper;

    protected function setUp(): void
    {
        if (!interface_exists(StorageInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->storage = $this->createMock(StorageInterface::class);
        $this->mappingHelper = $this->createMock(VichMappingHelper::class);
        $this->resolver = new VichUploaderResolver($this->storage, $this->mappingHelper);
    }

    public function testResolveWithStringSource(): void
    {
        $result = $this->resolver->resolve('/uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $result->path);
        self::assertNull($result->width);
        self::assertNull($result->height);
    }

    public function testResolveWithEntityAndFieldContext(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, 'imageFile')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('2024/february/photo.jpg');

        $result = $this->resolver->resolve('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]);

        self::assertSame('2024/february/photo.jpg', $result->path);
    }

    public function testResolveAutoDetectsFieldWhenNull(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, null)
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('auto/detected.jpg');

        $result = $this->resolver->resolve('photo.jpg', [
            'entity' => $entity,
        ]);

        self::assertSame('auto/detected.jpg', $result->path);
    }

    public function testResolveFallsBackWhenNoMappingFound(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->with($entity, null)
            ->willReturn(null);

        $result = $this->resolver->resolve('photo.jpg', [
            'entity' => $entity,
        ]);

        self::assertSame('photo.jpg', $result->path);
        self::assertNull($result->width);
    }

    public function testResolveWithEntityStripsLeadingSlash(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('/photo.jpg');

        $result = $this->resolver->resolve('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]);

        self::assertSame('photo.jpg', $result->path);
    }

    public function testResolveWithEntityReturnsEmptyOnNull(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')
            ->willReturn(null);

        $result = $this->resolver->resolve('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]);

        self::assertSame('', $result->path);
    }

    public function testResolveDetectsDimensionsFromGetterMethod(): void
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

        $result = $this->resolver->resolve('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]);

        self::assertSame(1920, $result->width);
        self::assertSame(1080, $result->height);
    }

    public function testResolveDetectsDimensionsFromEmbeddedFileObject(): void
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

        $result = $this->resolver->resolve('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]);

        self::assertSame(800, $result->width);
        self::assertSame(600, $result->height);
    }

    public function testResolveReturnsNullDimensionsWhenNoMethodAvailable(): void
    {
        $entity = new \stdClass();

        $this->mappingHelper->method('getFilePropertyName')
            ->willReturn('imageFile');

        $this->storage->method('resolvePath')->willReturn('photo.jpg');

        $result = $this->resolver->resolve('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
        ]);

        self::assertNull($result->width);
        self::assertNull($result->height);
    }

    public function testResolveSkipsDimensionDetectionWhenSourceDimensionsProvided(): void
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

        $result = $this->resolver->resolve('photo.jpg', [
            'entity' => $entity,
            'field' => 'imageFile',
            'sourceWidth' => 500,
            'sourceHeight' => 400,
        ]);

        self::assertSame(500, $result->width);
        self::assertSame(400, $result->height);
    }

    public function testResolveFallsBackToStringWhenNoEntity(): void
    {
        $result = $this->resolver->resolve('uploads/photo.jpg', [
            'mapping' => 'products',
        ]);

        self::assertSame('uploads/photo.jpg', $result->path);
    }
}
