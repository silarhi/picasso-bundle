<?php

declare(strict_types=1);

/*
 * This file is part of the Picasso Bundle package.
 *
 * (c) SILARHI <dev@silarhi.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Service\UrlAliases;

class UrlAliasesTest extends TestCase
{
    private UrlAliases $aliases;

    protected function setUp(): void
    {
        $this->aliases = new UrlAliases(['product_image' => 'p'], ['glide' => 'g']);
    }

    public function testSegmentsAreTheAliases(): void
    {
        self::assertSame('p', $this->aliases->loaderSegment('product_image'));
        self::assertSame('g', $this->aliases->transformerSegment('glide'));
    }

    public function testSegmentsAreTheNamesWithoutAnAlias(): void
    {
        self::assertSame('filesystem', $this->aliases->loaderSegment('filesystem'));
        self::assertSame('imgix', $this->aliases->transformerSegment('imgix'));
    }

    public function testAnAliasResolvesToItsName(): void
    {
        self::assertSame('product_image', $this->aliases->resolveLoader('p'));
        self::assertSame('glide', $this->aliases->resolveTransformer('g'));
    }

    public function testANameWithoutAnAliasResolvesToItself(): void
    {
        self::assertSame('filesystem', $this->aliases->resolveLoader('filesystem'));
        self::assertSame('imgix', $this->aliases->resolveTransformer('imgix'));
    }

    public function testANameStillResolvesOnceItHasAnAlias(): void
    {
        // URLs minted before the alias was set keep being served
        self::assertSame('product_image', $this->aliases->resolveLoader('product_image'));
        self::assertSame('glide', $this->aliases->resolveTransformer('glide'));
    }
}
