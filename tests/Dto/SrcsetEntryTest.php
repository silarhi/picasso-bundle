<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;

class SrcsetEntryTest extends TestCase
{
    public function testConstructor(): void
    {
        $entry = new SrcsetEntry('/img/photo.jpg?w=640', '640w');

        self::assertSame('/img/photo.jpg?w=640', $entry->url);
        self::assertSame('640w', $entry->descriptor);
    }

    public function testToString(): void
    {
        $entry = new SrcsetEntry('/img/photo.jpg?w=640', '640w');

        self::assertSame('/img/photo.jpg?w=640 640w', $entry->toString());
    }

    public function testToStringWithDensityDescriptor(): void
    {
        $entry = new SrcsetEntry('/img/photo.jpg?w=300', '1x');

        self::assertSame('/img/photo.jpg?w=300 1x', $entry->toString());
    }
}
