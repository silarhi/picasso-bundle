<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageDimensions;
use Silarhi\PicassoBundle\Dto\LoaderContext;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Vich\UploaderBundle\Storage\StorageInterface;

class VichUploaderLoaderTest extends TestCase
{
    private VichUploaderLoader $loader;
    private StorageInterface $storage;

    protected function setUp(): void
    {
        if (!interface_exists(StorageInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->storage = $this->createMock(StorageInterface::class);
        $this->loader = new VichUploaderLoader($this->storage);
    }

    public function testResolvePathWithString(): void
    {
        $context = new LoaderContext(source: '/uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $this->loader->resolvePath($context));
    }

    public function testResolvePathWithEntityUsesStorageRelativePath(): void
    {
        $entity = new \stdClass();

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('2024/february/photo.jpg');

        $context = new LoaderContext(source: $entity, field: 'imageFile');

        self::assertSame('2024/february/photo.jpg', $this->loader->resolvePath($context));
    }

    public function testResolvePathWithEntityStripsLeadingSlash(): void
    {
        $entity = new \stdClass();

        $this->storage->method('resolvePath')
            ->with($entity, 'imageFile', null, true)
            ->willReturn('/photo.jpg');

        $context = new LoaderContext(source: $entity, field: 'imageFile');

        self::assertSame('photo.jpg', $this->loader->resolvePath($context));
    }

    public function testResolvePathWithEntityReturnsEmptyOnNull(): void
    {
        $entity = new \stdClass();

        $this->storage->method('resolvePath')
            ->willReturn(null);

        $context = new LoaderContext(source: $entity, field: 'imageFile');

        self::assertSame('', $this->loader->resolvePath($context));
    }

    public function testGetDimensionsReturnsNullForString(): void
    {
        $context = new LoaderContext(source: 'some/path.jpg');

        self::assertNull($this->loader->getDimensions($context));
    }

    public function testGetDimensionsFromGetterMethod(): void
    {
        $entity = new class {
            public function getImageDimensions(): array
            {
                return [1920, 1080];
            }
        };

        $context = new LoaderContext(source: $entity, field: 'image');
        $dims = $this->loader->getDimensions($context);

        self::assertInstanceOf(ImageDimensions::class, $dims);
        self::assertSame(1920, $dims->width);
        self::assertSame(1080, $dims->height);
    }

    public function testGetDimensionsFromEmbeddedFileObject(): void
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

            public function getImage(): object
            {
                return $this->file;
            }
        };

        $context = new LoaderContext(source: $entity, field: 'image');
        $dims = $this->loader->getDimensions($context);

        self::assertInstanceOf(ImageDimensions::class, $dims);
        self::assertSame(800, $dims->width);
        self::assertSame(600, $dims->height);
    }

    public function testGetDimensionsReturnsNullWhenNoMethodAvailable(): void
    {
        $entity = new \stdClass();
        $context = new LoaderContext(source: $entity, field: 'image');

        self::assertNull($this->loader->getDimensions($context));
    }

    public function testContextExtraCanBeUsed(): void
    {
        $entity = new class {
            public function getImageDimensions(): array
            {
                return [640, 480];
            }
        };

        $context = new LoaderContext(
            source: $entity,
            field: 'image',
            extra: ['mapping' => 'products'],
        );

        self::assertSame('products', $context->getExtra('mapping'));

        $dims = $this->loader->getDimensions($context);
        self::assertInstanceOf(ImageDimensions::class, $dims);
        self::assertSame(640, $dims->width);
    }
}
