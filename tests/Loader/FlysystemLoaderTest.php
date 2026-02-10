<?php

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\LoaderContext;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;

class FlysystemLoaderTest extends TestCase
{
    private FlysystemLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new FlysystemLoader();
    }

    public function testResolvePathReturnsPathAsIs(): void
    {
        $context = new LoaderContext(source: 'uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $this->loader->resolvePath($context));
    }

    public function testResolvePathStripsLeadingSlash(): void
    {
        $context = new LoaderContext(source: '/uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $this->loader->resolvePath($context));
    }

    public function testGetDimensionsAlwaysReturnsNull(): void
    {
        $context = new LoaderContext(source: 'uploads/photo.jpg');

        self::assertNull($this->loader->getDimensions($context));
    }
}
