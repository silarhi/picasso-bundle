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

namespace Silarhi\PicassoBundle\Tests\Twig\Component;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;

class ImageComponentTest extends TestCase
{
    private MockObject&ImageHelperInterface $imageHelper;

    protected function setUp(): void
    {
        $this->imageHelper = $this->createMock(ImageHelperInterface::class);
    }

    private function createComponent(): ImageComponent
    {
        return new ImageComponent($this->imageHelper);
    }

    public function testDefaultValues(): void
    {
        $component = $this->createComponent();

        self::assertNull($component->src);
        self::assertNull($component->loader);
        self::assertNull($component->transformer);
        self::assertNull($component->quality);
        self::assertNull($component->fit);
        self::assertNull($component->placeholder);
        self::assertNull($component->placeholderData);
        self::assertFalse($component->priority);
        self::assertNull($component->loading);
        self::assertNull($component->fetchPriority);
        self::assertFalse($component->unoptimized);
        self::assertSame([], $component->context);
    }

    public function testComputeImageDataDelegatesToImageHelper(): void
    {
        $sources = [
            new ImageSource(type: 'image/avif', srcset: '/img/photo.avif 640w'),
            new ImageSource(type: 'image/webp', srcset: '/img/photo.webp 640w'),
        ];

        $renderData = new ImageRenderData(
            fallbackSrc: '/img/photo.jpg',
            fallbackSrcset: '/img/photo.jpg 640w',
            sources: $sources,
            placeholderUri: '/img/photo.jpg?blur=50',
            width: 1920,
            height: 1080,
            loading: 'lazy',
            fetchPriority: null,
            sizes: '100vw',
            unoptimized: false,
        );

        $this->imageHelper->expects(self::once())
            ->method('imageData')
            ->with(
                'uploads/photo.jpg',  // src
                null,                  // width
                null,                  // height
                '100vw',               // sizes
                null,                  // loader
                null,                  // transformer
                null,                  // quality
                null,                  // fit
                null,                  // placeholder
                null,                  // placeholderData
                false,                 // priority
                null,                  // loading
                null,                  // fetchPriority
                false,                 // unoptimized
                null,                  // sourceWidth
                null,                  // sourceHeight
                [],                    // context
            )
            ->willReturn($renderData);

        $component = $this->createComponent();
        $component->src = 'uploads/photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('/img/photo.jpg', $component->fallbackSrc);
        self::assertSame('/img/photo.jpg 640w', $component->fallbackSrcset);
        self::assertSame($sources, $component->sources);
        self::assertSame('/img/photo.jpg?blur=50', $component->placeholderUri);
        self::assertSame(1920, $component->width);
        self::assertSame(1080, $component->height);
        self::assertSame('lazy', $component->loading);
        self::assertNull($component->fetchPriority);
    }

    public function testComputeImageDataPassesAllProps(): void
    {
        $renderData = new ImageRenderData(
            fallbackSrc: '/images/logo.svg',
            fallbackSrcset: null,
            sources: [],
            placeholderUri: null,
            width: 200,
            height: 50,
            loading: 'eager',
            fetchPriority: 'high',
            sizes: '50vw',
            unoptimized: true,
        );

        $this->imageHelper->expects(self::once())
            ->method('imageData')
            ->with(
                '/images/logo.svg',              // src
                200,                              // width
                50,                               // height
                '50vw',                           // sizes
                'vich',                           // loader
                'imgix',                          // transformer
                90,                               // quality
                'cover',                          // fit
                'blur',                           // placeholder
                'data:image/png;base64,abc',      // placeholderData
                true,                             // priority
                'eager',                          // loading
                'high',                           // fetchPriority
                true,                             // unoptimized
                400,                              // sourceWidth
                100,                              // sourceHeight
                ['entity' => 'User'],             // context
            )
            ->willReturn($renderData);

        $component = $this->createComponent();
        $component->src = '/images/logo.svg';
        $component->width = 200;
        $component->height = 50;
        $component->sizes = '50vw';
        $component->loader = 'vich';
        $component->transformer = 'imgix';
        $component->quality = 90;
        $component->fit = 'cover';
        $component->placeholder = 'blur';
        $component->placeholderData = 'data:image/png;base64,abc';
        $component->priority = true;
        $component->loading = 'eager';
        $component->fetchPriority = 'high';
        $component->unoptimized = true;
        $component->sourceWidth = 400;
        $component->sourceHeight = 100;
        $component->context = ['entity' => 'User'];
        $component->computeImageData();

        self::assertSame('/images/logo.svg', $component->fallbackSrc);
        self::assertSame(200, $component->width);
        self::assertSame(50, $component->height);
        self::assertSame('eager', $component->loading);
        self::assertSame('high', $component->fetchPriority);
    }

    public function testComputeImageDataUpdatesComputedDimensions(): void
    {
        $renderData = new ImageRenderData(
            fallbackSrc: '/img/photo.jpg',
            fallbackSrcset: '/img/photo.jpg 640w',
            sources: [],
            placeholderUri: null,
            width: 640,
            height: 480,
            loading: 'lazy',
            fetchPriority: null,
            sizes: null,
            unoptimized: false,
        );

        $this->imageHelper->method('imageData')->willReturn($renderData);

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        self::assertNull($component->width);
        self::assertNull($component->height);

        $component->computeImageData();

        // Dimensions are updated from the render data
        self::assertSame(640, $component->width);
        self::assertSame(480, $component->height);
    }
}
