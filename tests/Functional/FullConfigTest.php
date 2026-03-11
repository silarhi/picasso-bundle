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

namespace Silarhi\PicassoBundle\Tests\Functional;

use function assert;

use Silarhi\PicassoBundle\Loader\FilesystemLoader;
use Silarhi\PicassoBundle\Placeholder\TransformerPlaceholder;
use Silarhi\PicassoBundle\Service\ImageHelper;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\PlaceholderRegistry;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubServicePlaceholder;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubServiceTransformer;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Silarhi\PicassoBundle\Transformer\ImgixTransformer;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Tests that exercise the full breadth of bundle configuration:
 * - Multiple filesystem loaders with different names
 * - Disabled loader / transformer
 * - Imgix transformer alongside Glide
 * - Service-type transformer
 * - Custom device_sizes, image_sizes, formats, quality, fit
 * - Custom blur placeholder settings
 * - Explicit default_loader / default_transformer
 * - Upscaling prevention with custom sizes
 * - max_image_size on Glide
 */
class FullConfigTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    protected static function getKernelClass(): string
    {
        return FullConfigKernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $kernel = self::bootKernel();
        $glideCache = $kernel->getCacheDir() . '/glide';
        if (!is_dir($glideCache)) {
            mkdir($glideCache, 0777, true);
        }
    }

    // --- Loader registry tests ---

    public function testMultipleFilesystemLoadersWithDifferentNames(): void
    {
        $registry = self::getContainer()->get('picasso.loader_registry');
        assert($registry instanceof LoaderRegistry);

        self::assertTrue($registry->has('main'));
        self::assertTrue($registry->has('secondary_fs'));
        self::assertTrue($registry->has('third_fs'));
        self::assertInstanceOf(FilesystemLoader::class, $registry->get('main'));
        self::assertInstanceOf(FilesystemLoader::class, $registry->get('secondary_fs'));
        self::assertInstanceOf(FilesystemLoader::class, $registry->get('third_fs'));
    }

    public function testDisabledLoaderNotInRegistry(): void
    {
        $registry = self::getContainer()->get('picasso.loader_registry');
        assert($registry instanceof LoaderRegistry);

        self::assertFalse($registry->has('disabled_loader'));
    }

    // --- Transformer registry tests ---

    public function testGlideTransformerRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.transformer_registry');
        assert($registry instanceof TransformerRegistry);

        self::assertTrue($registry->has('local_glide'));
        self::assertInstanceOf(GlideTransformer::class, $registry->get('local_glide'));
    }

    public function testImgixTransformersRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.transformer_registry');
        assert($registry instanceof TransformerRegistry);

        self::assertTrue($registry->has('cdn_imgix'));
        self::assertInstanceOf(ImgixTransformer::class, $registry->get('cdn_imgix'));

        self::assertTrue($registry->has('imgix_unsigned'));
        self::assertInstanceOf(ImgixTransformer::class, $registry->get('imgix_unsigned'));
    }

    public function testServiceTransformerRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.transformer_registry');
        assert($registry instanceof TransformerRegistry);

        self::assertTrue($registry->has('custom_service'));
        self::assertInstanceOf(StubServiceTransformer::class, $registry->get('custom_service'));
    }

    public function testDisabledTransformerNotInRegistry(): void
    {
        $registry = self::getContainer()->get('picasso.transformer_registry');
        assert($registry instanceof TransformerRegistry);

        self::assertFalse($registry->has('disabled_transformer'));
    }

    // --- Default loader / transformer aliasing ---

    public function testDefaultLoaderAlias(): void
    {
        $container = self::getContainer();

        self::assertTrue($container->has('picasso.default_loader'));
        $defaultLoader = $container->get('picasso.default_loader');
        self::assertInstanceOf(FilesystemLoader::class, $defaultLoader);
    }

    public function testDefaultTransformerAlias(): void
    {
        $container = self::getContainer();

        self::assertTrue($container->has('picasso.default_transformer'));
        $defaultTransformer = $container->get('picasso.default_transformer');
        self::assertInstanceOf(GlideTransformer::class, $defaultTransformer);
    }

    // --- Pipeline uses explicit defaults ---

    public function testPipelineUsesExplicitDefaultLoader(): void
    {
        $pipeline = self::getContainer()->get('picasso.pipeline');
        assert($pipeline instanceof ImagePipeline);

        $resolvedLoader = $pipeline->resolveLoaderName();
        self::assertSame('main', $resolvedLoader);
    }

    public function testPipelineUsesExplicitDefaultTransformer(): void
    {
        $pipeline = self::getContainer()->get('picasso.pipeline');
        assert($pipeline instanceof ImagePipeline);

        $resolvedTransformer = $pipeline->resolveTransformerName();
        self::assertSame('local_glide', $resolvedTransformer);
    }

    public function testPipelineAllowsOverridingLoader(): void
    {
        $pipeline = self::getContainer()->get('picasso.pipeline');
        assert($pipeline instanceof ImagePipeline);

        $resolvedLoader = $pipeline->resolveLoaderName('secondary_fs');
        self::assertSame('secondary_fs', $resolvedLoader);
    }

    public function testPipelineAllowsOverridingTransformer(): void
    {
        $pipeline = self::getContainer()->get('picasso.pipeline');
        assert($pipeline instanceof ImagePipeline);

        $resolvedTransformer = $pipeline->resolveTransformerName('cdn_imgix');
        self::assertSame('cdn_imgix', $resolvedTransformer);
    }

    // --- Custom device_sizes and image_sizes ---

    public function testCustomDeviceSizesWiredToSrcsetGenerator(): void
    {
        $generator = self::getContainer()->get('picasso.srcset_generator');
        assert($generator instanceof SrcsetGenerator);

        $widths = $generator->getWidths('100vw', null);

        // device_sizes=[320, 640, 1024] + image_sizes=[24, 48, 96] => sorted & unique
        self::assertContains(320, $widths);
        self::assertContains(640, $widths);
        self::assertContains(1024, $widths);
        self::assertContains(24, $widths);
        self::assertContains(48, $widths);
        self::assertContains(96, $widths);
        // Original default sizes should not be present
        self::assertNotContains(1920, $widths);
        self::assertNotContains(3840, $widths);
        self::assertNotContains(128, $widths);
        self::assertNotContains(384, $widths);
    }

    // --- Custom formats ---

    public function testCustomFormatsUsedInRendering(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        // Config sets formats: ['webp', 'png']
        self::assertStringContainsString('type="image/webp"', $html);
        // png is the fallback format (last), so it appears as the <img> srcset, not as a <source>
        self::assertStringNotContainsString('type="image/avif"', $html);
        self::assertStringNotContainsString('type="image/jpeg"', $html);
    }

    // --- Custom quality and fit ---

    public function testImageHelperUsesCustomQualityAndFit(): void
    {
        $helper = self::getContainer()->get('picasso.image_helper');
        assert($helper instanceof ImageHelper);

        // Uses default quality=90 and fit=cover from config
        $url = $helper->imageUrl('pixel.gif', width: 50, format: 'gif');

        self::assertStringContainsString('q=90', $url);
        self::assertStringContainsString('fit=cover', $url);
    }

    // --- Imgix transformer URL generation ---

    public function testImgixTransformerSignedUrl(): void
    {
        $registry = self::getContainer()->get('picasso.transformer_registry');
        assert($registry instanceof TransformerRegistry);
        $imgix = $registry->get('cdn_imgix');

        $url = $imgix->url(
            new \Silarhi\PicassoBundle\Dto\Image(path: 'photo.jpg'),
            new \Silarhi\PicassoBundle\Dto\ImageTransformation(width: 200, format: 'webp', quality: 80, fit: 'contain'),
        );

        self::assertStringStartsWith('https://test.imgix.net/photo.jpg', $url);
        self::assertStringContainsString('w=200', $url);
        self::assertStringContainsString('fm=webp', $url);
        self::assertStringContainsString('q=80', $url);
        self::assertStringContainsString('s=', $url); // signed
    }

    public function testImgixTransformerUnsignedUrl(): void
    {
        $registry = self::getContainer()->get('picasso.transformer_registry');
        assert($registry instanceof TransformerRegistry);
        $imgix = $registry->get('imgix_unsigned');

        $url = $imgix->url(
            new \Silarhi\PicassoBundle\Dto\Image(path: 'photo.jpg'),
            new \Silarhi\PicassoBundle\Dto\ImageTransformation(width: 100, format: 'jpg', quality: 75, fit: 'cover'),
        );

        self::assertStringStartsWith('https://unsigned.imgix.net/photo.jpg', $url);
        self::assertStringContainsString('w=100', $url);
        self::assertStringNotContainsString('s=', $url); // not signed
    }

    // --- Service transformer URL generation ---

    public function testServiceTransformerUrlGeneration(): void
    {
        $registry = self::getContainer()->get('picasso.transformer_registry');
        assert($registry instanceof TransformerRegistry);
        $custom = $registry->get('custom_service');

        $url = $custom->url(
            new \Silarhi\PicassoBundle\Dto\Image(path: 'test.jpg'),
            new \Silarhi\PicassoBundle\Dto\ImageTransformation(width: 300, format: 'png'),
        );

        self::assertSame('/service-transformer/test.jpg?w=300&fm=png', $url);
    }

    // --- Mount with explicit loader and transformer overrides ---

    public function testMountWithExplicitNonDefaultLoader(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'pixel.gif',
            'loader' => 'third_fs',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertNotNull($component->fallbackSrc);
        self::assertNotEmpty($component->sources);
    }

    public function testMountWithExplicitTransformerOverride(): void
    {
        // Uses imgix transformer instead of default glide
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'transformer' => 'cdn_imgix',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        // Imgix URLs start with https://test.imgix.net
        self::assertNotNull($component->fallbackSrc);
        self::assertStringContainsString('imgix.net', $component->fallbackSrc);
    }

    public function testMountWithServiceTransformer(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'transformer' => 'custom_service',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertNotNull($component->fallbackSrc);
        self::assertStringContainsString('/service-transformer/', $component->fallbackSrc);
    }

    // --- Placeholder registry tests ---

    public function testTransformerPlaceholderRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.placeholder_registry');
        assert($registry instanceof PlaceholderRegistry);

        self::assertTrue($registry->has('blur'));
        self::assertInstanceOf(TransformerPlaceholder::class, $registry->get('blur'));
    }

    public function testServicePlaceholderRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.placeholder_registry');
        assert($registry instanceof PlaceholderRegistry);

        self::assertTrue($registry->has('custom_placeholder'));
        self::assertInstanceOf(StubServicePlaceholder::class, $registry->get('custom_placeholder'));
    }

    // --- Placeholder with custom settings ---

    public function testBlurPlaceholderUsesCustomConfig(): void
    {
        // Config has blur size=20, blur=10, quality=50
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertNotNull($component->placeholderUri);
        // The blur URL should contain the configured blur amount (10) and quality (50)
        self::assertStringContainsString('blur=10', $component->placeholderUri);
    }

    public function testPlaceholderStringSelectsNamedPlaceholder(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
            'placeholder' => 'custom_placeholder',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame('data:image/png;base64,service-placeholder', $component->placeholderUri);
    }

    public function testPlaceholderDataBypassesPlaceholderService(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
            'placeholderData' => 'data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame('data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==', $component->placeholderUri);
    }

    // --- Upscaling prevention ---

    public function testUpscalingPreventedWithCustomSizes(): void
    {
        // photo.jpg is 100x50 — with explicit sourceWidth/sourceHeight, display dims are capped
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'width' => 200,
            'height' => 100,
            'sourceWidth' => 100,
            'sourceHeight' => 50,
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame(100, $component->width);
        self::assertSame(50, $component->height);
    }

    public function testUpscalingPreventedOnWidthOnly(): void
    {
        // photo.jpg is 100x50 — requesting width=500 should be capped to 100
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'width' => 500,
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame(100, $component->width);
    }

    public function testSmallImageDoesNotUpscale(): void
    {
        // pixel.gif is 1x1 — with explicit sourceWidth/sourceHeight, display dims are capped
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'pixel.gif',
            'width' => 100,
            'height' => 100,
            'sourceWidth' => 1,
            'sourceHeight' => 1,
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame(1, $component->width);
        self::assertSame(1, $component->height);
    }

    // --- Custom fit propagation ---

    public function testMountWithExplicitFitOverride(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
            'fit' => 'crop',
        ]);
        assert($component instanceof ImageComponent);

        // The fallback URL should contain fit=crop
        self::assertNotNull($component->fallbackSrc);
        self::assertStringContainsString('fit=crop', $component->fallbackSrc);
    }

    public function testMountUsesDefaultFitFromConfig(): void
    {
        // Config default_fit = 'cover'
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertNotNull($component->fallbackSrc);
        self::assertStringContainsString('fit=cover', $component->fallbackSrc);
    }

    // --- Custom quality propagation ---

    public function testMountWithExplicitQualityOverride(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
            'quality' => 50,
        ]);
        assert($component instanceof ImageComponent);

        // The fallback URL should contain q=50
        self::assertNotNull($component->fallbackSrc);
        self::assertStringContainsString('q=50', $component->fallbackSrc);
    }

    public function testMountUsesDefaultQualityFromConfig(): void
    {
        // Config default_quality = 90
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertNotNull($component->fallbackSrc);
        self::assertStringContainsString('q=90', $component->fallbackSrc);
    }

    // --- Render-level tests with non-default config ---

    public function testRenderUsesCustomFormats(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('type="image/webp"', $html);
        self::assertStringContainsString('srcset=', $html);
        // No avif or jpeg sources
        self::assertStringNotContainsString('type="image/avif"', $html);
        self::assertStringNotContainsString('type="image/jpeg"', $html);
    }

    public function testRenderWithImgixTransformer(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'transformer' => 'cdn_imgix',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('imgix.net', $html);
    }

    public function testRenderWithServiceTransformer(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'transformer' => 'custom_service',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('/service-transformer/', $html);
    }

    public function testRenderPlaceholderWithCustomSettings(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('background-image:url(', $html);
    }
}
