<?php

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\LoaderContext;

class LoaderContextTest extends TestCase
{
    public function testStringSource(): void
    {
        $context = new LoaderContext(source: 'uploads/photo.jpg');

        self::assertFalse($context->isEntity());
        self::assertSame('uploads/photo.jpg', $context->getSourceAsString());
        self::assertNull($context->field);
        self::assertSame([], $context->extra);
    }

    public function testEntitySource(): void
    {
        $entity = new \stdClass();
        $context = new LoaderContext(source: $entity, field: 'imageFile');

        self::assertTrue($context->isEntity());
        self::assertSame($entity, $context->source);
        self::assertSame('imageFile', $context->field);
    }

    public function testExtraParams(): void
    {
        $context = new LoaderContext(
            source: 'photo.jpg',
            extra: ['mapping' => 'products', 'cdn' => 'us-east'],
        );

        self::assertSame('products', $context->getExtra('mapping'));
        self::assertSame('us-east', $context->getExtra('cdn'));
        self::assertNull($context->getExtra('nonexistent'));
        self::assertSame('fallback', $context->getExtra('nonexistent', 'fallback'));
    }
}
