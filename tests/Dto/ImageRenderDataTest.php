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

namespace Silarhi\PicassoBundle\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Dto\ImageSource;

class ImageRenderDataTest extends TestCase
{
    public function testJsonSerializeFullData(): void
    {
        $sources = [
            new ImageSource(type: 'image/avif', srcset: '/img/photo.avif 640w, /img/photo.avif 1080w'),
            new ImageSource(type: 'image/webp', srcset: '/img/photo.webp 640w'),
        ];

        $data = new ImageRenderData(
            fallbackSrc: '/img/photo.jpg',
            fallbackSrcset: '/img/photo.jpg 640w',
            sources: $sources,
            placeholderUri: 'data:image/png;base64,abc',
            width: 1920,
            height: 1080,
            loading: 'lazy',
            fetchPriority: null,
            sizes: '100vw',
            unoptimized: false,
            attributes: ['alt' => 'A photo', 'class' => 'hero'],
        );

        $json = $data->jsonSerialize();

        self::assertSame('/img/photo.jpg', $json['fallbackSrc']);
        self::assertSame('/img/photo.jpg 640w', $json['fallbackSrcset']);
        self::assertCount(2, $json['sources']);
        self::assertSame('image/avif', $json['sources'][0]['type']);
        self::assertSame('/img/photo.avif 640w, /img/photo.avif 1080w', $json['sources'][0]['srcset']);
        self::assertSame('image/webp', $json['sources'][1]['type']);
        self::assertSame('data:image/png;base64,abc', $json['placeholderUri']);
        self::assertSame(1920, $json['width']);
        self::assertSame(1080, $json['height']);
        self::assertSame('lazy', $json['loading']);
        self::assertNull($json['fetchPriority']);
        self::assertSame('100vw', $json['sizes']);
        self::assertFalse($json['unoptimized']);
        self::assertSame(['alt' => 'A photo', 'class' => 'hero'], $json['attributes']);
    }

    public function testJsonSerializeUnoptimized(): void
    {
        $data = new ImageRenderData(
            fallbackSrc: '/images/logo.svg',
            fallbackSrcset: null,
            sources: [],
            placeholderUri: null,
            width: 200,
            height: 50,
            loading: 'eager',
            fetchPriority: 'high',
            sizes: null,
            unoptimized: true,
        );

        $json = $data->jsonSerialize();

        self::assertSame('/images/logo.svg', $json['fallbackSrc']);
        self::assertNull($json['fallbackSrcset']);
        self::assertSame([], $json['sources']);
        self::assertNull($json['placeholderUri']);
        self::assertTrue($json['unoptimized']);
        self::assertSame('eager', $json['loading']);
        self::assertSame('high', $json['fetchPriority']);
        self::assertSame([], $json['attributes']);
    }

    public function testJsonEncodeProducesValidJson(): void
    {
        $data = new ImageRenderData(
            fallbackSrc: '/img/photo.jpg',
            fallbackSrcset: '/img/photo.jpg 640w',
            sources: [new ImageSource(type: 'image/webp', srcset: '/img/photo.webp 640w')],
            placeholderUri: null,
            width: 640,
            height: 480,
            loading: 'lazy',
            fetchPriority: null,
            sizes: '100vw',
            unoptimized: false,
        );

        $encoded = json_encode($data, \JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /* @var array<string, mixed> $decoded */
        self::assertSame('/img/photo.jpg', $decoded['fallbackSrc']);
        /** @var list<array{type: string, srcset: string}> $sources */
        $sources = $decoded['sources'];
        self::assertCount(1, $sources);
        self::assertSame('image/webp', $sources[0]['type']);
    }
}
