<?php

namespace Silarhi\PicassoBundle\Tests\Twig\Component;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\BlurPlaceholderConfig;
use Silarhi\PicassoBundle\Dto\ImageParams;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Dto\ResolvedImage;
use Silarhi\PicassoBundle\Dto\SrcsetEntry;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Resolver\ImageResolverInterface;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;

class ImageComponentTest extends TestCase
{
    private SrcsetGenerator $srcsetGenerator;
    private ContainerInterface $resolvers;
    private ContainerInterface $loaders;
    private ImageResolverInterface $filesystemResolver;
    private ImageLoaderInterface $glideLoader;

    protected function setUp(): void
    {
        $this->srcsetGenerator = $this->createMock(SrcsetGenerator::class);

        $this->filesystemResolver = $this->createMock(ImageResolverInterface::class);

        $this->resolvers = $this->createMock(ContainerInterface::class);
        $this->resolvers->method('get')
            ->with('filesystem')
            ->willReturn($this->filesystemResolver);

        $this->glideLoader = $this->createMock(ImageLoaderInterface::class);

        $this->loaders = $this->createMock(ContainerInterface::class);
        $this->loaders->method('get')
            ->with('glide')
            ->willReturn($this->glideLoader);
    }

    private function createComponent(bool $blurEnabled = false): ImageComponent
    {
        return new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            blurConfig: new BlurPlaceholderConfig(enabled: $blurEnabled),
            resolvers: $this->resolvers,
            loaders: $this->loaders,
            defaultResolver: 'filesystem',
            defaultLoader: 'glide',
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
        );
    }

    public function testComputeImageDataResolvesPathFromResolver(): void
    {
        $this->filesystemResolver->method('resolve')
            ->with('uploads/photo.jpg', [])
            ->willReturn(new ResolvedImage('uploads/photo.jpg', 1920, 1080));
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
        $this->filesystemResolver->method('resolve')
            ->willReturn(new ResolvedImage('photo.jpg'));
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

    public function testComputeImageDataFallsBackToResolverDimensions(): void
    {
        $this->filesystemResolver->method('resolve')
            ->willReturn(new ResolvedImage('photo.jpg', 1024, 768));
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
        $this->filesystemResolver->method('resolve')
            ->willReturn(new ResolvedImage('photo.jpg', 1920, 1080));
        $this->configureSrcsetGenerator();

        $this->glideLoader->method('getUrl')
            ->with('photo.jpg', self::callback(function (ImageParams $params): bool {
                return $params->width === 10
                    && $params->height === 6
                    && $params->format === 'jpg'
                    && $params->quality === 30
                    && $params->fit === 'crop'
                    && $params->blur === 50;
            }))
            ->willReturn('/picasso/image/photo.jpg?w=10&h=6&fm=jpg&q=30&blur=50');

        $component = $this->createComponent(blurEnabled: true);
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('/picasso/image/photo.jpg?w=10&h=6&fm=jpg&q=30&blur=50', $component->blurDataUri);
    }

    public function testComputeImageDataSkipsBlurWhenPlaceholderFalse(): void
    {
        $this->filesystemResolver->method('resolve')
            ->willReturn(new ResolvedImage('photo.jpg', 1920, 1080));
        $this->glideLoader->expects(self::never())->method('getUrl');
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
        $this->filesystemResolver->method('resolve')
            ->willReturn(new ResolvedImage('photo.jpg', 1920, 1080));
        $this->glideLoader->expects(self::never())->method('getUrl');
        $this->configureSrcsetGenerator();

        $component = $this->createComponent(blurEnabled: false);
        $component->src = 'photo.jpg';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNull($component->blurDataUri);
    }

    public function testComputeImageDataGeneratesSourcesAndFallback(): void
    {
        $this->filesystemResolver->method('resolve')
            ->willReturn(new ResolvedImage('photo.jpg'));

        $this->srcsetGenerator->method('generateSrcset')
            ->willReturnCallback(function (ImageLoaderInterface $loader, string $path, string $format): array {
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

        self::assertCount(2, $component->sources);
        self::assertInstanceOf(ImageSource::class, $component->sources[0]);
        self::assertInstanceOf(ImageSource::class, $component->sources[1]);
        self::assertSame('image/avif', $component->sources[0]->type);
        self::assertSame('image/webp', $component->sources[1]->type);
        self::assertStringContainsString('fm=avif', $component->sources[0]->srcset);
        self::assertStringContainsString('fm=webp', $component->sources[1]->srcset);

        self::assertSame('/img/photo.jpg?fm=jpg&w=800', $component->fallbackSrc);
        self::assertStringContainsString('fm=jpg', $component->fallbackSrcset);
    }

    public function testComputeImageDataUsesCustomResolver(): void
    {
        $customResolver = $this->createMock(ImageResolverInterface::class);
        $customResolver->method('resolve')
            ->willReturn(new ResolvedImage('custom/photo.jpg', 500, 500));

        $resolvers = $this->createMock(ContainerInterface::class);
        $resolvers->method('get')->with('custom')->willReturn($customResolver);

        $this->configureSrcsetGenerator();

        $component = new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            blurConfig: new BlurPlaceholderConfig(enabled: false),
            resolvers: $resolvers,
            loaders: $this->loaders,
            defaultResolver: 'filesystem',
            defaultLoader: 'glide',
            formats: ['avif', 'webp', 'jpg'],
            defaultQuality: 75,
        );

        $component->src = 'photo.jpg';
        $component->resolver = 'custom';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('custom/photo.jpg', $component->resolvedPath);
        self::assertSame(500, $component->width);
        self::assertSame(500, $component->height);
    }

    public function testComputeImageDataUsesCustomLoader(): void
    {
        $imgixLoader = $this->createMock(ImageLoaderInterface::class);

        $loaders = $this->createMock(ContainerInterface::class);
        $loaders->method('get')->with('imgix')->willReturn($imgixLoader);

        $this->filesystemResolver->method('resolve')
            ->willReturn(new ResolvedImage('photo.jpg', 800, 600));
        $this->configureSrcsetGenerator();

        $component = new ImageComponent(
            srcsetGenerator: $this->srcsetGenerator,
            blurConfig: new BlurPlaceholderConfig(enabled: false),
            resolvers: $this->resolvers,
            loaders: $loaders,
            defaultResolver: 'filesystem',
            defaultLoader: 'glide',
            formats: ['webp', 'jpg'],
            defaultQuality: 75,
        );

        $component->src = 'photo.jpg';
        $component->loader = 'imgix';
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('photo.jpg', $component->resolvedPath);
    }

    public function testComputeImageDataPassesContext(): void
    {
        $this->filesystemResolver->method('resolve')
            ->with('photo.jpg', ['mapping' => 'products'])
            ->willReturn(new ResolvedImage('photo.jpg', 100, 100));
        $this->configureSrcsetGenerator();

        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->context = ['mapping' => 'products'];
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertSame('photo.jpg', $component->resolvedPath);
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

    public function testUnoptimizedDefaultsToFalse(): void
    {
        $component = $this->createComponent();

        self::assertFalse($component->unoptimized);
    }

    public function testDefaultValues(): void
    {
        $component = $this->createComponent();

        self::assertNull($component->src);
        self::assertNull($component->resolver);
        self::assertNull($component->loader);
        self::assertNull($component->quality);
        self::assertSame('contain', $component->fit);
        self::assertNull($component->placeholder);
        self::assertFalse($component->unoptimized);
        self::assertSame([], $component->context);
    }

    public function testPlaceholderPropOverridesConfig(): void
    {
        $this->filesystemResolver->method('resolve')
            ->willReturn(new ResolvedImage('photo.jpg', 800, 600));
        $this->configureSrcsetGenerator();

        $this->glideLoader->method('getUrl')
            ->willReturn('/picasso/image/photo.jpg?w=10&h=8&fm=jpg&q=30&blur=50');

        $component = $this->createComponent(blurEnabled: false);
        $component->src = 'photo.jpg';
        $component->placeholder = true;
        $component->sizes = '100vw';
        $component->computeImageData();

        self::assertNotNull($component->blurDataUri);
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
