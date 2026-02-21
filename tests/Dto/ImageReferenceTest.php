<?php

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageReference;

class ImageReferenceTest extends TestCase
{
    public function testDefaults(): void
    {
        $ref = new ImageReference();

        self::assertNull($ref->path);
        self::assertSame([], $ref->context);
    }

    public function testWithPath(): void
    {
        $ref = new ImageReference('uploads/photo.jpg');

        self::assertSame('uploads/photo.jpg', $ref->path);
    }

    public function testWithContext(): void
    {
        $entity = new \stdClass();
        $ref = new ImageReference('photo.jpg', ['entity' => $entity, 'field' => 'imageFile']);

        self::assertSame($entity, $ref->context['entity']);
        self::assertSame('imageFile', $ref->context['field']);
    }
}
