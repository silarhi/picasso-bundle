<?php

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\Image;

class ImageTest extends TestCase
{
    public function testDefaults(): void
    {
        $image = new Image();

        self::assertNull($image->path);
        self::assertNull($image->url);
        self::assertNull($image->stream);
        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->mimeType);
    }

    public function testWithPath(): void
    {
        $image = new Image(path: 'uploads/photo.jpg', width: 1920, height: 1080);

        self::assertSame('uploads/photo.jpg', $image->path);
        self::assertSame(1920, $image->width);
        self::assertSame(1080, $image->height);
    }

    public function testWithUrl(): void
    {
        $image = new Image(url: 'https://cdn.example.com/photo.jpg');

        self::assertSame('https://cdn.example.com/photo.jpg', $image->url);
        self::assertNull($image->path);
    }

    public function testWithMimeType(): void
    {
        $image = new Image(path: 'photo.webp', mimeType: 'image/webp');

        self::assertSame('image/webp', $image->mimeType);
    }

    public function testReadonlyProperties(): void
    {
        $image = new Image(path: 'photo.jpg');
        $reflection = new \ReflectionClass($image);

        self::assertTrue($reflection->getProperty('path')->isReadOnly());
        self::assertTrue($reflection->getProperty('width')->isReadOnly());
        self::assertTrue($reflection->getProperty('height')->isReadOnly());
        self::assertTrue($reflection->getProperty('mimeType')->isReadOnly());
    }
}
