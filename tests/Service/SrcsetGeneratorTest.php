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
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

class SrcsetGeneratorTest extends TestCase
{
    private SrcsetGenerator $generator;
    private \PHPUnit\Framework\MockObject\MockObject&ImageTransformerInterface $transformer;

    protected function setUp(): void
    {
        $this->transformer = $this->createMock(ImageTransformerInterface::class);
        $this->transformer->method('url')
            ->willReturnCallback(static function (Image $image, ImageTransformation $t, array $context): string {
                $query = [];
                if (null !== $t->width) {
                    $query['w'] = $t->width;
                }
                if (null !== $t->height) {
                    $query['h'] = $t->height;
                }
                if (null !== $t->format) {
                    $query['fm'] = $t->format;
                }
                if (null !== $t->quality) {
                    $query['q'] = $t->quality;
                }
                if (null !== $t->fit) {
                    $query['fit'] = $t->fit;
                }

                return '/picasso/glide/filesystem/' . $image->path . '?' . http_build_query($query);
            });

        $this->generator = new SrcsetGenerator(
            deviceSizes: [640, 750, 1080, 1920],
            imageSizes: [32, 64, 128, 256],
            defaultQuality: 75,
            defaultFit: 'contain',
        );
    }

    public function testGetWidthsResponsiveMode(): void
    {
        $widths = $this->generator->getWidths(sizes: '100vw', width: null);

        self::assertSame([32, 64, 128, 256, 640, 750, 1080, 1920], $widths);
    }

    public function testGetWidthsFixedMode(): void
    {
        $widths = $this->generator->getWidths(sizes: null, width: 400);

        self::assertSame([400, 800], $widths);
    }

    public function testGetWidthsFallbackWhenNoSizesNoWidth(): void
    {
        $widths = $this->generator->getWidths(sizes: null, width: null);

        self::assertSame([32, 64, 128, 256, 640, 750, 1080, 1920], $widths);
    }

    public function testGetWidthsDeduplicates(): void
    {
        $generator = new SrcsetGenerator(
            deviceSizes: [640, 1080],
            imageSizes: [640, 128],
            defaultQuality: 75,
            defaultFit: 'contain',
        );

        $widths = $generator->getWidths(sizes: '100vw', width: null);

        self::assertSame([128, 640, 1080], $widths);
    }

    public function testGenerateSrcsetResponsiveUsesWidthDescriptors(): void
    {
        $image = new Image(path: 'photo.jpg');

        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'webp',
            sizes: '100vw',
        );

        self::assertCount(8, $entries);
        foreach ($entries as $entry) {
            self::assertInstanceOf(SrcsetEntry::class, $entry);
            self::assertMatchesRegularExpression('/^\d+w$/', $entry->descriptor);
            self::assertStringContainsString('fm=webp', $entry->url);
        }
    }

    public function testGenerateSrcsetFixedUsesDensityDescriptors(): void
    {
        $image = new Image(path: 'photo.jpg');

        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'webp',
            width: 300,
            height: 200,
        );

        self::assertCount(2, $entries);
        self::assertSame('1x', $entries[0]->descriptor);
        self::assertSame('2x', $entries[1]->descriptor);
    }

    public function testGenerateSrcsetFixedPreservesAspectRatio(): void
    {
        $image = new Image(path: 'photo.jpg');

        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'jpg',
            width: 300,
            height: 200,
        );

        self::assertStringContainsString('w=300', $entries[0]->url);
        self::assertStringContainsString('h=200', $entries[0]->url);
        self::assertStringContainsString('w=600', $entries[1]->url);
        self::assertStringContainsString('h=400', $entries[1]->url);
    }

    public function testGenerateSrcsetResponsivePreservesAspectRatio(): void
    {
        $image = new Image(path: 'photo.jpg');

        // A 1200x500 banner: every candidate keeps the 12:5 crop, not the source's ratio
        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'jpg',
            width: 1200,
            height: 500,
            sizes: '100vw',
        );

        $heights = [];
        foreach ($entries as $entry) {
            parse_str((string) parse_url($entry->url, \PHP_URL_QUERY), $query);
            self::assertArrayHasKey('h', $query, $entry->url);
            $heights[(int) $query['w']] = (int) $query['h'];
        }

        self::assertSame(267, $heights[640]);
        self::assertSame(800, $heights[1920]);
    }

    public function testGenerateSrcsetResponsiveWithoutHeightKeepsSourceRatio(): void
    {
        $image = new Image(path: 'photo.jpg');

        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'jpg',
            width: 1200,
            sizes: '100vw',
        );

        foreach ($entries as $entry) {
            self::assertStringNotContainsString('h=', $entry->url);
        }
    }

    public function testGenerateSrcsetCapsTallCropsToSourceHeight(): void
    {
        $image = new Image(path: 'photo.jpg');

        // A 1:2 crop of a 1800x1200 photo: past 600w, the crop would be taller than the source
        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'jpg',
            width: 500,
            height: 1000,
            sizes: '100vw',
            sourceWidth: 1800,
            sourceHeight: 1200,
        );

        $widestCandidate = 0;
        $tallestCandidate = 0;
        foreach ($entries as $entry) {
            parse_str((string) parse_url($entry->url, \PHP_URL_QUERY), $query);
            $widestCandidate = max($widestCandidate, (int) $query['w']);
            $tallestCandidate = max($tallestCandidate, (int) $query['h']);
        }

        self::assertSame(600, $widestCandidate);
        self::assertSame(1200, $tallestCandidate);
    }

    public function testGenerateSrcsetCapsWideCropsToSourceWidth(): void
    {
        $image = new Image(path: 'photo.jpg');

        // A 12:5 banner of a 1800x2700 photo: the source width is the limit
        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'jpg',
            width: 1200,
            height: 500,
            sizes: '100vw',
            sourceWidth: 1800,
            sourceHeight: 2700,
        );

        $widest = array_pop($entries);
        self::assertNotNull($widest);
        self::assertStringContainsString('w=1800&h=750', $widest->url);
    }

    public function testGenerateSrcsetUsesCustomQuality(): void
    {
        $image = new Image(path: 'photo.jpg');

        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'webp',
            width: 300,
            quality: 90,
        );

        foreach ($entries as $entry) {
            self::assertStringContainsString('q=90', $entry->url);
        }
    }

    public function testGenerateSrcsetUsesDefaultQuality(): void
    {
        $image = new Image(path: 'photo.jpg');

        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'webp',
            width: 300,
        );

        foreach ($entries as $entry) {
            self::assertStringContainsString('q=75', $entry->url);
        }
    }

    public function testBuildSrcsetString(): void
    {
        $entries = [
            new SrcsetEntry('/img/photo.jpg?w=300', '1x'),
            new SrcsetEntry('/img/photo.jpg?w=600', '2x'),
        ];

        $result = $this->generator->buildSrcsetString($entries);

        self::assertSame('/img/photo.jpg?w=300 1x, /img/photo.jpg?w=600 2x', $result);
    }

    public function testGetFallbackUrl(): void
    {
        $image = new Image(path: 'photo.jpg');

        $url = $this->generator->getFallbackUrl(
            transformer: $this->transformer,
            image: $image,
            format: 'jpg',
            width: 800,
            height: 600,
            quality: 80,
        );

        self::assertStringContainsString('fm=jpg', $url);
        self::assertStringContainsString('w=800', $url);
        self::assertStringContainsString('h=600', $url);
        self::assertStringContainsString('q=80', $url);
    }

    public function testGenerateSrcsetIncludesFitParam(): void
    {
        $image = new Image(path: 'photo.jpg');

        $entries = $this->generator->generateSrcset(
            transformer: $this->transformer,
            image: $image,
            format: 'webp',
            width: 300,
            fit: 'crop',
        );

        foreach ($entries as $entry) {
            self::assertStringContainsString('fit=crop', $entry->url);
        }
    }

    public function testGenerateSrcsetPassesContext(): void
    {
        $contextReceived = [];
        $transformer = $this->createMock(ImageTransformerInterface::class);
        $transformer->method('url')
            ->willReturnCallback(static function (Image $image, ImageTransformation $t, array $context) use (&$contextReceived): string {
                $contextReceived = $context;

                return '/url';
            });

        $image = new Image(path: 'photo.jpg');

        $this->generator->generateSrcset(
            transformer: $transformer,
            image: $image,
            format: 'webp',
            width: 300,
            context: ['loader' => 'vich', 'transformer' => 'glide'],
        );

        self::assertSame('vich', $contextReceived['loader']);
        self::assertSame('glide', $contextReceived['transformer']);
    }
}
