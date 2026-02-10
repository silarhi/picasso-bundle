<?php

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Url\ImageUrlGeneratorInterface;

class SrcsetGeneratorTest extends TestCase
{
    private SrcsetGenerator $generator;

    protected function setUp(): void
    {
        $urlGenerator = $this->createMock(ImageUrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->willReturnCallback(function (string $path, ImageParams $params): string {
                $query = [];
                if ($params->width !== null) {
                    $query['w'] = $params->width;
                }
                if ($params->height !== null) {
                    $query['h'] = $params->height;
                }
                if ($params->format !== null) {
                    $query['fm'] = $params->format;
                }
                if ($params->quality !== null) {
                    $query['q'] = $params->quality;
                }
                $query['fit'] = $params->fit;

                return '/picasso/image/'.$path.'?'.http_build_query($query);
            });

        $this->generator = new SrcsetGenerator(
            urlGenerator: $urlGenerator,
            deviceSizes: [640, 750, 1080, 1920],
            imageSizes: [32, 64, 128, 256],
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
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
        $urlGenerator = $this->createMock(ImageUrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/img');

        $generator = new SrcsetGenerator(
            urlGenerator: $urlGenerator,
            deviceSizes: [640, 1080],
            imageSizes: [640, 128], // 640 is a duplicate
            formats: ['jpg'],
            defaultQuality: 75,
        );

        $widths = $generator->getWidths(sizes: '100vw', width: null);

        self::assertSame([128, 640, 1080], $widths);
    }

    public function testGenerateSrcsetResponsiveUsesWidthDescriptors(): void
    {
        $entries = $this->generator->generateSrcset(
            path: 'photo.jpg',
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
        $entries = $this->generator->generateSrcset(
            path: 'photo.jpg',
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
        $entries = $this->generator->generateSrcset(
            path: 'photo.jpg',
            format: 'jpg',
            width: 300,
            height: 200,
        );

        // 1x: w=300, h=200
        self::assertStringContainsString('w=300', $entries[0]->url);
        self::assertStringContainsString('h=200', $entries[0]->url);

        // 2x: w=600, h=400
        self::assertStringContainsString('w=600', $entries[1]->url);
        self::assertStringContainsString('h=400', $entries[1]->url);
    }

    public function testGenerateSrcsetUsesCustomQuality(): void
    {
        $entries = $this->generator->generateSrcset(
            path: 'photo.jpg',
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
        $entries = $this->generator->generateSrcset(
            path: 'photo.jpg',
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

    public function testBuildSrcsetStringWithWidthDescriptors(): void
    {
        $entries = [
            new SrcsetEntry('/img/photo.jpg?w=640', '640w'),
            new SrcsetEntry('/img/photo.jpg?w=1080', '1080w'),
        ];

        $result = $this->generator->buildSrcsetString($entries);

        self::assertSame('/img/photo.jpg?w=640 640w, /img/photo.jpg?w=1080 1080w', $result);
    }

    public function testGetFallbackUrl(): void
    {
        $url = $this->generator->getFallbackUrl(
            path: 'photo.jpg',
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

    public function testGetFallbackUrlWithoutDimensions(): void
    {
        $url = $this->generator->getFallbackUrl(
            path: 'photo.jpg',
            format: 'webp',
        );

        self::assertStringContainsString('fm=webp', $url);
        self::assertStringNotContainsString('w=', $url);
        self::assertStringNotContainsString('h=', $url);
    }

    public function testGetFormats(): void
    {
        self::assertSame(['avif', 'webp', 'jpg'], $this->generator->getFormats());
    }

    public function testGenerateSrcsetIncludesFitParam(): void
    {
        $entries = $this->generator->generateSrcset(
            path: 'photo.jpg',
            format: 'webp',
            width: 300,
            fit: 'crop',
        );

        foreach ($entries as $entry) {
            self::assertStringContainsString('fit=crop', $entry->url);
        }
    }
}
