<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
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
        self::assertSame('uploads/photo.jpg', $this->loader->resolvePath('/uploads/photo.jpg'));
    }

    public function testResolvePathWithEntity(): void
    {
        $entity = new \stdClass();

        $this->uploaderHelper->method('asset')
            ->with($entity, 'imageFile')
            ->willReturn('/uploads/images/photo.jpg');

        self::assertSame('uploads/images/photo.jpg', $this->loader->resolvePath($entity, 'imageFile'));
    }

    public function testResolvePathWithEntityReturnsEmptyOnNull(): void
    {
        $entity = new \stdClass();

        $this->uploaderHelper->method('asset')
            ->willReturn(null);

        self::assertSame('', $this->loader->resolvePath($entity, 'imageFile'));
    }

    public function testGetDimensionsReturnsNullForString(): void
    {
        self::assertNull($this->loader->getDimensions('some/path.jpg'));
    }

    public function testGetDimensionsFromGetterMethod(): void
    {
        $entity = new class {
            public function getImageDimensions(): array
            {
                return [1920, 1080];
            }
        };

        $dims = $this->loader->getDimensions($entity, 'image');

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

        $dims = $this->loader->getDimensions($entity, 'image');

        self::assertNotNull($dims);
        self::assertSame(800, $dims[0]);
        self::assertSame(600, $dims[1]);
    }

    public function testGetDimensionsReturnsNullWhenNoMethodAvailable(): void
    {
        $entity = new \stdClass();

        self::assertNull($this->loader->getDimensions($entity, 'image'));
    }
}
