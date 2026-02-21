<?php

namespace Silarhi\PicassoBundle\Tests\Twig\Component;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;

class ImageComponentTest extends TestCase
{
    private SrcsetGenerator $srcsetGenerator;
    private ContainerInterface $loaders;
    private ContainerInterface $transformers;
    private ImageLoaderInterface $filesystemLoader;
    private ImageTransformerInterface $glideTransformer;

    protected function setUp(): void
    {
        $this->srcsetGenerator = $this->createMock(SrcsetGenerator::class);

        $this->filesystemLoader = $this->createMock(ImageLoaderInterface::class);

        $this->loaders = $this->createMock(ContainerInterface::class);
        $this->loaders->method('get')
            ->with('filesystem')
            ->willReturn($this->filesystemLoader);

        $this->glideTransformer = $this->createMock(ImageTransformerInterface::class);

        $this->transformers = $this->createMock(ContainerInterface::class);
        $this->transformers->method('get')
            ->with('glide')
            ->willReturn($this->glideTransformer);
    }

    private function createComponent(bool $blurEnabled = false): ImageComponent
    {
        return new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            loaders: $this->loaders,
            transformers: $this->transformers,
            defaultLoader: 'filesystem',
            defaultTransformer: 'glide',
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
            blurEnabled: $blurEnabled,
            blurSize: 10,
            blurAmount: 50,
            blurQuality: 30,
        );
    }

    public function testComputeImageDataLoadsImageFromLoader(): void
    {
        $this->filesystemLoader->method('load')
            ->willReturn(new Image(path: 'uploads/photo.jpg', width: 1920, height: 1080));
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'uploads/photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('uploads/photo.jpg', $component->resolvedPath);
    }

    public function testComputeImageDataSkipsWhenSrcIsNull(): void
    {
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->computeImageData();

        self::assertSame('', $component->resolvedPath);
        self::assertSame('', $component->fallbackSrc);
        self::assertSame([], $component->sources);
    }

    public function testComputeImageDataUsesSourceWidthHeight(): void
    {
        $this->filesystemLoader->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->sourceWidth = 800;
        $component->sourceHeight = 600;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame(800, $component->width);
        self::assertSame(600, $component->height);
    }

    public function testComputeImageDataFallsBackToLoaderDimensions(): void
    {
        $this->filesystemLoader->method('load')
            ->willReturn(new Image(path: 'photo.jpg', width: 1024, height: 768));
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame(1024, $component->width);
        self::assertSame(768, $component->height);
    }

    public function testComputeImageDataGeneratesBlurPlaceholder(): void
    {
        $this->filesystemLoader->method('load')
            ->willReturn(new Image(path: 'photo.jpg', width: 1920, height: 1080));
        $this->configureSrcsetGenerator();

        $this->glideTransformer->method('url')
            ->with(
                self::isInstanceOf(Image::class),
                self::callback(function (ImageTransformation $t): bool {
                    return $t->width === 10
                        && $t->height === 6
                        && $t->format === 'jpg'
                        && $t->quality === 30
                        && $t->fit === 'crop'
                        && $t->blur === 50;
                }),
                ['loader' => 'filesystem'],
            )
            ->willReturn('/picasso/glide/filesystem/photo.jpg?w=10&h=6&fm=jpg&q=30&blur=50');

        $component = $this->createComponent(blurEnabled: true);
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('/picasso/glide/filesystem/photo.jpg?w=10&h=6&fm=jpg&q=30&blur=50', $component->blurDataUri);
    }

    public function testComputeImageDataSkipsBlurWhenPlaceholderFalse(): void
    {
        $this->filesystemLoader->method('load')
            ->willReturn(new Image(path: 'photo.jpg', width: 1920, height: 1080));
        $this->glideTransformer->expects(self::never())->method('url');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent(blurEnabled: true);
        $component->src = 'photo.jpg';
        $component->placeholder = false;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNull($component->blurDataUri);
    }

    public function testComputeImageDataSkipsBlurWhenConfigDisabled(): void
    {
        $this->filesystemLoader->method('load')
            ->willReturn(new Image(path: 'photo.jpg', width: 1920, height: 1080));
        $this->glideTransformer->expects(self::never())->method('url');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent(blurEnabled: false);
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNull($component->blurDataUri);
    }

    public function testComputeImageDataGeneratesSourcesAndFallback(): void
    {
        $this->filesystemLoader->method('load')
            ->willReturn(new Image(path: 'photo.jpg'));

        $this->srcsetGenerator->method('generateSrcset')
            ->willReturnCallback(function (ImageTransformerInterface $t, Image $img, string $format): array {
                return [
                    new SrcsetEntry("/img/{$img->path}?fm={$format}&w=640", '640w'),
                    new SrcsetEntry("/img/{$img->path}?fm={$format}&w=1080", '1080w'),
                ];
            });

        $this->srcsetGenerator->method('buildSrcsetString')
            ->willReturnCallback(function (array $entries): string {
                return implode(', ', array_map(
                    static fn (SrcsetEntry $e) => $e->toString(),
                    $entries,
                ));
            });

        $this->srcsetGenerator->method('getFallbackUrl')
            ->willReturn('/img/photo.jpg?fm=jpg&w=800');

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->width = 800;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertCount(2, $component->sources);
        self::assertInstanceOf(ImageSource::class, $component->sources[0]);
        self::assertInstanceOf(ImageSource::class, $component->sources[1]);
        self::assertSame('image/avif', $component->sources[0]->type);
        self::assertSame('image/webp', $component->sources[1]->type);

        self::assertSame('/img/photo.jpg?fm=jpg&w=800', $component->fallbackSrc);
        self::assertStringContainsString('fm=jpg', $component->fallbackSrcset);
    }

    public function testComputeImageDataUsesCustomLoader(): void
    {
        $customLoader = $this->createMock(ImageLoaderInterface::class);
        $customLoader->method('load')
            ->willReturn(new Image(path: 'custom/photo.jpg', width: 500, height: 500));

        $loaders = $this->createMock(ContainerInterface::class);
        $loaders->method('get')->with('custom')->willReturn($customLoader);

        $this->configureSrcsetGenerator();

        $component = new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            loaders: $loaders,
            transformers: $this->transformers,
            defaultLoader: 'filesystem',
            defaultTransformer: 'glide',
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
            blurEnabled: false,
            blurSize: 10,
            blurAmount: 50,
            blurQuality: 30,
        );

        $component->src = 'photo.jpg';
        $component->loader = 'custom';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('custom/photo.jpg', $component->resolvedPath);
        self::assertSame(500, $component->width);
        self::assertSame(500, $component->height);
    }

    public function testUnoptimizedServesRawSrc(): void
    {
        $this->srcsetGenerator->expects(self::never())->method('generateSrcset');
        $this->srcsetGenerator->expects(self::never())->method('getFallbackUrl');

        $component = $this->createComponent();
        $component->src = '/images/logo.svg';
        $component->unoptimized = true;
        $component->width = 200;
        $component->height = 50;
        $component->computeImageData();

        self::assertSame('/images/logo.svg', $component->fallbackSrc);
        self::assertSame('', $component->fallbackSrcset);
        self::assertSame([], $component->sources);
        self::assertNull($component->blurDataUri);
    }

    public function testDefaultValues(): void
    {
        $component = $this->createComponent();

        self::assertNull($component->src);
        self::assertNull($component->loader);
        self::assertNull($component->transformer);
        self::assertNull($component->quality);
        self::assertSame('contain', $component->fit);
        self::assertNull($component->placeholder);
        self::assertFalse($component->unoptimized);
        self::assertSame([], $component->context);
    }

    public function testPlaceholderPropOverridesConfig(): void
    {
        $this->filesystemLoader->method('load')
            ->willReturn(new Image(path: 'photo.jpg', width: 800, height: 600));
        $this->configureSrcsetGenerator();

        $this->glideTransformer->method('url')
            ->willReturn('/picasso/glide/filesystem/photo.jpg?w=10&h=8&fm=jpg&q=30&blur=50');

        $component = $this->createComponent(blurEnabled: false);
        $component->src = 'photo.jpg';
        $component->placeholder = true;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNotNull($component->blurDataUri);
    }

    public function testWithMetadataIsFalseWhenBothSourceDimensionsProvided(): void
    {
        $this->filesystemLoader->expects(self::once())
            ->method('load')
            ->with(
                self::isInstanceOf(\Silarhi\PicassoBundle\Dto\ImageReference::class),
                false,
            )
            ->willReturn(new Image(path: 'photo.jpg'));

        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->sourceWidth = 800;
        $component->sourceHeight = 600;
        $component->sizes = '100vw';
        $component->computeImageData();
    }

    private function configureSrcsetGenerator(): void
    {
        $this->srcsetGenerator->method('generateSrcset')->willReturn([
            new SrcsetEntry('/img/photo.jpg?w=640', '640w'),
        ]);
        $this->srcsetGenerator->method('buildSrcsetString')->willReturn('/img/photo.jpg?w=640 640w');
        $this->srcsetGenerator->method('getFallbackUrl')->willReturn('/img/photo.jpg');
    }
}
