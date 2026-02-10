<?php

namespace Silarhi\PicassoBundle\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Resolver\FlysystemResolver;

class FlysystemResolverTest extends TestCase
{
    private FlysystemResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FlysystemResolver();
    }

    public function testResolveReturnsPathAsIs(): void
    {
        $result = $this->resolver->resolve('uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $result->path);
    }

    public function testResolveStripsLeadingSlash(): void
    {
        $result = $this->resolver->resolve('/uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $result->path);
    }

    public function testResolveAlwaysReturnsNullDimensions(): void
    {
        $result = $this->resolver->resolve('uploads/photo.jpg');

        self::assertNull($result->width);
        self::assertNull($result->height);
    }

    public function testResolveAcceptsStorageContext(): void
    {
        $result = $this->resolver->resolve('uploads/photo.jpg', ['storage' => 'public_uploads']);

        self::assertSame('uploads/photo.jpg', $result->path);
    }
}
