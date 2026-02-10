<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\LoaderContext;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Vich\UploaderBundle\Templating\Helper\UploaderHelperInterface;

class VichUploaderLoaderTest extends TestCase
{
    private VichUploaderLoader $loader;
    private UploaderHelperInterface $uploaderHelper;

    protected function setUp(): void
    {
        if (!interface_exists(UploaderHelperInterface::class)) {
            self::markTestSkipped('VichUploaderBundle is not installed.');
        }

        $this->uploaderHelper = $this->createMock(UploaderHelperInterface::class);
        $this->loader = new VichUploaderLoader($this->uploaderHelper);
    }

    public function testResolvePathWithString(): void
    {
        $context = new LoaderContext(source: '/uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $this->loader->resolvePath($context));
    }

    public function testResolvePathWithEntity(): void
    {
        $entity = new \stdClass();

        $this->uploaderHelper->method('asset')
            ->with($entity, 'imageFile')
            ->willReturn('/uploads/images/photo.jpg');

        $context = new LoaderContext(source: $entity, field: 'imageFile');

        self::assertSame('uploads/images/photo.jpg', $this->loader->resolvePath($context));
    }

    public function testResolvePathWithEntityReturnsEmptyOnNull(): void
    {
        $entity = new \stdClass();

        $this->uploaderHelper->method('asset')
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

        self::assertNotNull($dims);
        self::assertSame(1920, $dims[0]);
        self::assertSame(1080, $dims[1]);
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

        self::assertNotNull($dims);
        self::assertSame(800, $dims[0]);
        self::assertSame(600, $dims[1]);
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
        self::assertNotNull($dims);
        self::assertSame(640, $dims[0]);
    }
}
