<?php

namespace Silarhi\PicassoBundle\Tests\Twig\Component;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\ImageDimensions;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\LoaderContext;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;
use Silarhi\PicassoBundle\Loader\LoaderInterface;
use Silarhi\PicassoBundle\Service\BlurHashGenerator;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;
use Silarhi\PicassoBundle\Url\ImageUrlGeneratorInterface;

class ImageComponentTest extends TestCase
{
    private SrcsetGenerator $srcsetGenerator;
    private BlurHashGenerator $blurHashGenerator;
    private ContainerInterface $loaders;
    private ContainerInterface $providers;
    private LoaderInterface $fileLoader;
    private ImageUrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        $this->srcsetGenerator = $this->createMock(SrcsetGenerator::class);
        $this->blurHashGenerator = $this->createMock(BlurHashGenerator::class);

        $this->fileLoader = $this->createMock(LoaderInterface::class);

        $this->loaders = $this->createMock(ContainerInterface::class);
        $this->loaders->method('get')
            ->with('file')
            ->willReturn($this->fileLoader);

        $this->urlGenerator = $this->createMock(ImageUrlGeneratorInterface::class);

        $this->providers = $this->createMock(ContainerInterface::class);
        $this->providers->method('get')
            ->with('glide')
            ->willReturn($this->urlGenerator);
    }

    private function createComponent(): ImageComponent
    {
        return new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            blurHashGenerator: $this->blurHashGenerator,
            loaders: $this->loaders,
            providers: $this->providers,
            defaultLoader: 'file',
            defaultProvider: 'glide',
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
        );
    }

    public function testComputeImageDataResolvesPathFromLoader(): void
    {
        $this->fileLoader->method('resolvePath')
            ->with(self::callback(fn (LoaderContext $ctx) => $ctx->getSourceAsString() === 'uploads/photo.jpg'))
            ->willReturn('uploads/photo.jpg');
        $this->fileLoader->method('getDimensions')->willReturn(new ImageDimensions(1920, 1080));
        $this->blurHashGenerator->method('isEnabled')->willReturn(false);
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'uploads/photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('uploads/photo.jpg', $component->resolvedPath);
    }

    public function testComputeImageDataUsesSourceWidthHeight(): void
    {
        $this->fileLoader->method('resolvePath')->willReturn('photo.jpg');
        $this->fileLoader->expects(self::never())->method('getDimensions');
        $this->blurHashGenerator->method('isEnabled')->willReturn(false);
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
        $this->fileLoader->method('resolvePath')->willReturn('photo.jpg');
        $this->fileLoader->method('getDimensions')->willReturn(new ImageDimensions(1024, 768));
        $this->blurHashGenerator->method('isEnabled')->willReturn(false);
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
        $this->fileLoader->method('resolvePath')->willReturn('photo.jpg');
        $this->fileLoader->method('getDimensions')->willReturn(new ImageDimensions(1920, 1080));
        $this->blurHashGenerator->method('isEnabled')->willReturn(true);
        $this->blurHashGenerator->method('generate')
            ->with('photo.jpg', 1920, 1080)
            ->willReturn('data:image/jpeg;base64,abc123');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('data:image/jpeg;base64,abc123', $component->blurDataUri);
    }

    public function testComputeImageDataSkipsBlurWhenPlaceholderFalse(): void
    {
        $this->fileLoader->method('resolvePath')->willReturn('photo.jpg');
        $this->fileLoader->method('getDimensions')->willReturn(new ImageDimensions(1920, 1080));
        $this->blurHashGenerator->expects(self::never())->method('generate');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->placeholder = false;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNull($component->blurDataUri);
    }

    public function testComputeImageDataGeneratesSourcesAndFallback(): void
    {
        $this->fileLoader->method('resolvePath')->willReturn('photo.jpg');
        $this->fileLoader->method('getDimensions')->willReturn(null);
        $this->blurHashGenerator->method('isEnabled')->willReturn(false);

        $this->srcsetGenerator->method('generateSrcset')
            ->willReturnCallback(function (ImageUrlGeneratorInterface $urlGen, string $path, string $format): array {
                return [
                    new SrcsetEntry("/img/{$path}?fm={$format}&w=640", '640w'),
                    new SrcsetEntry("/img/{$path}?fm={$format}&w=1080", '1080w'),
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

        // avif and webp should be in sources, jpg should be fallback
        self::assertCount(2, $component->sources);
        self::assertInstanceOf(ImageSource::class, $component->sources[0]);
        self::assertInstanceOf(ImageSource::class, $component->sources[1]);
        self::assertSame('image/avif', $component->sources[0]->type);
        self::assertSame('image/webp', $component->sources[1]->type);
        self::assertStringContainsString('fm=avif', $component->sources[0]->srcset);
        self::assertStringContainsString('fm=webp', $component->sources[1]->srcset);

        // Fallback
        self::assertSame('/img/photo.jpg?fm=jpg&w=800', $component->fallbackSrc);
        self::assertStringContainsString('fm=jpg', $component->fallbackSrcset);
    }

    public function testComputeImageDataUsesCustomLoader(): void
    {
        $customLoader = $this->createMock(LoaderInterface::class);
        $customLoader->method('resolvePath')->willReturn('custom/photo.jpg');
        $customLoader->method('getDimensions')->willReturn(new ImageDimensions(500, 500));

        $loaders = $this->createMock(ContainerInterface::class);
        $loaders->method('get')->with('custom')->willReturn($customLoader);

        $this->blurHashGenerator->method('isEnabled')->willReturn(false);
        $this->configureSrcsetGenerator();

        $component = new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            blurHashGenerator: $this->blurHashGenerator,
            loaders: $loaders,
            providers: $this->providers,
            defaultLoader: 'file',
            defaultProvider: 'glide',
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
        );

        $component->src = 'photo.jpg';
        $component->loader = 'custom';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('custom/photo.jpg', $component->resolvedPath);
        self::assertSame(500, $component->width);
        self::assertSame(500, $component->height);
    }

    public function testComputeImageDataUsesCustomProvider(): void
    {
        $imgixUrlGenerator = $this->createMock(ImageUrlGeneratorInterface::class);

        $providers = $this->createMock(ContainerInterface::class);
        $providers->method('get')->with('imgix')->willReturn($imgixUrlGenerator);

        $this->fileLoader->method('resolvePath')->willReturn('photo.jpg');
        $this->fileLoader->method('getDimensions')->willReturn(new ImageDimensions(800, 600));
        $this->blurHashGenerator->method('isEnabled')->willReturn(false);
        $this->configureSrcsetGenerator();

        $component = new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            blurHashGenerator: $this->blurHashGenerator,
            loaders: $this->loaders,
            providers: $providers,
            defaultLoader: 'file',
            defaultProvider: 'glide',
            formats: ['webp', 'jpg'],
            defaultQuality: 75,
        );

        $component->src = 'photo.jpg';
        $component->provider = 'imgix';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('photo.jpg', $component->resolvedPath);
    }

    public function testComputeImageDataPassesLoaderExtra(): void
    {
        $this->fileLoader->method('resolvePath')
            ->with(self::callback(function (LoaderContext $ctx): bool {
                return $ctx->getExtra('mapping') === 'products'
                    && $ctx->field === 'imageFile';
            }))
            ->willReturn('photo.jpg');
        $this->fileLoader->method('getDimensions')->willReturn(new ImageDimensions(100, 100));
        $this->blurHashGenerator->method('isEnabled')->willReturn(false);
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->field = 'imageFile';
        $component->loaderExtra = ['mapping' => 'products'];
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('photo.jpg', $component->resolvedPath);
    }

    public function testDefaultValues(): void
    {
        $component = $this->createComponent();

        self::assertSame('', $component->alt);
        self::assertSame('lazy', $component->loading);
        self::assertNull($component->loader);
        self::assertNull($component->provider);
        self::assertNull($component->quality);
        self::assertSame('contain', $component->fit);
        self::assertNull($component->placeholder);
        self::assertSame([], $component->loaderExtra);
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
