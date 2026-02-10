<?php

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageParams;

class ImageParamsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $params = new ImageParams();

        self::assertNull($params->width);
        self::assertNull($params->height);
        self::assertNull($params->format);
        self::assertNull($params->quality);
        self::assertSame('contain', $params->fit);
        self::assertNull($params->blur);
        self::assertNull($params->dpr);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $params = new ImageParams(width: 100);
        $new = $params->withWidth(200);

        self::assertSame(100, $params->width);
        self::assertSame(200, $new->width);
        self::assertNotSame($params, $new);
    }

    public function testWithHeightPreservesOtherFields(): void
    {
        $params = new ImageParams(width: 100, format: 'webp', quality: 80);
        $new = $params->withHeight(200);

        self::assertSame(100, $new->width);
        self::assertSame(200, $new->height);
        self::assertSame('webp', $new->format);
        self::assertSame(80, $new->quality);
    }

    public function testWithFormatReturnsNewInstance(): void
    {
        $params = new ImageParams(format: 'jpg');
        $new = $params->withFormat('avif');

        self::assertSame('jpg', $params->format);
        self::assertSame('avif', $new->format);
    }

    public function testWithQualityReturnsNewInstance(): void
    {
        $params = new ImageParams(quality: 75);
        $new = $params->withQuality(90);

        self::assertSame(75, $params->quality);
        self::assertSame(90, $new->quality);
    }

    public function testWithFitReturnsNewInstance(): void
    {
        $params = new ImageParams(fit: 'contain');
        $new = $params->withFit('crop');

        self::assertSame('contain', $params->fit);
        self::assertSame('crop', $new->fit);
    }

    public function testWithBlurReturnsNewInstance(): void
    {
        $params = new ImageParams();
        $new = $params->withBlur(50);

        self::assertNull($params->blur);
        self::assertSame(50, $new->blur);
    }

    public function testWithDprReturnsNewInstance(): void
    {
        $params = new ImageParams();
        $new = $params->withDpr(2);

        self::assertNull($params->dpr);
        self::assertSame(2, $new->dpr);
    }

    public function testImmutabilityChain(): void
    {
        $params = (new ImageParams())
            ->withWidth(300)
            ->withHeight(200)
            ->withFormat('webp')
            ->withQuality(85)
            ->withFit('crop');

        self::assertSame(300, $params->width);
        self::assertSame(200, $params->height);
        self::assertSame('webp', $params->format);
        self::assertSame(85, $params->quality);
        self::assertSame('crop', $params->fit);
    }
}
