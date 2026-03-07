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
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\PlaceholderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributeLoader;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributePlaceholder;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributeTransformer;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

class MultiConfigTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    protected static function getKernelClass(): string
    {
        return MultiConfigKernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Ensure glide cache dir exists
        $kernel = self::bootKernel();
        $glideCache = $kernel->getCacheDir() . '/glide';
        if (!is_dir($glideCache)) {
            mkdir($glideCache, 0777, true);
        }
    }

    // --- Registry tests ---

    public function testMultipleFilesystemLoadersRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.loader_registry');
        assert($registry instanceof LoaderRegistry);

        self::assertTrue($registry->has('primary'));
        self::assertTrue($registry->has('secondary'));
        self::assertInstanceOf(FilesystemLoader::class, $registry->get('primary'));
        self::assertInstanceOf(FilesystemLoader::class, $registry->get('secondary'));
    }

    public function testDisabledLoaderNotRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.loader_registry');
        assert($registry instanceof LoaderRegistry);

        self::assertFalse($registry->has('disabled_loader'));
    }

    public function testAttributeLoaderIsRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.loader_registry');
        assert($registry instanceof LoaderRegistry);

        self::assertTrue($registry->has('stub'));
        self::assertInstanceOf(StubAttributeLoader::class, $registry->get('stub'));
    }

    public function testAttributeTransformerIsRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.transformer_registry');
        assert($registry instanceof TransformerRegistry);

        self::assertTrue($registry->has('stub'));
        self::assertInstanceOf(StubAttributeTransformer::class, $registry->get('stub'));
    }

    public function testAttributePlaceholderIsRegistered(): void
    {
        $registry = self::getContainer()->get('picasso.placeholder_registry');
        assert($registry instanceof PlaceholderRegistry);

        self::assertTrue($registry->has('stub'));
        self::assertInstanceOf(StubAttributePlaceholder::class, $registry->get('stub'));
    }

    // --- Mount-level tests (computed properties) ---

    public function testMountResolvesDefaultLoading(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame('lazy', $component->loading);
        self::assertNull($component->fetchPriority);
    }

    public function testMountPrioritySetsEagerAndHigh(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
            'priority' => true,
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame('eager', $component->loading);
        self::assertSame('high', $component->fetchPriority);
        self::assertNull($component->placeholderUri, 'Priority should disable blur placeholder');
    }

    public function testMountUnoptimizedPreservesSrc(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => '/images/logo.svg',
            'unoptimized' => true,
            'width' => 200,
            'height' => 50,
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame('/images/logo.svg', $component->fallbackSrc);
        self::assertEmpty($component->sources);
    }

    public function testMountResolvesSourceDimensions(): void
    {
        // photo.jpg is 100x50
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame(100, $component->width);
        self::assertSame(50, $component->height);
    }

    public function testMountExplicitDimensionsWithinBounds(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'width' => 80,
            'height' => 40,
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame(80, $component->width);
        self::assertSame(40, $component->height);
    }

    public function testMountPreventsUpscaling(): void
    {
        // photo.jpg is 100x50 — requesting 200x100 should be capped
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'width' => 200,
            'height' => 100,
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame(100, $component->width);
        self::assertSame(50, $component->height);
    }

    public function testMountSourceDimensionsOverrideMetadata(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sourceWidth' => 80,
            'sourceHeight' => 40,
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertSame(80, $component->width);
        self::assertSame(40, $component->height);
    }

    public function testMountGeneratesPlaceholder(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertNotNull($component->placeholderUri);
    }

    public function testMountWithExplicitLoader(): void
    {
        $component = $this->mountTwigComponent('Picasso:Image', [
            'src' => 'pixel.gif',
            'loader' => 'secondary',
            'sizes' => '100vw',
        ]);
        assert($component instanceof ImageComponent);

        self::assertNotNull($component->fallbackSrc);
        self::assertNotEmpty($component->sources);
    }

    // --- Render-level tests (HTML output) ---

    public function testRenderResponsivePicture(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('<source', $html);
        self::assertStringContainsString('type="image/avif"', $html);
        self::assertStringContainsString('type="image/webp"', $html);
        self::assertStringContainsString('srcset=', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('width="100"', $html);
        self::assertStringContainsString('height="50"', $html);
    }

    public function testRenderUnoptimizedImg(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => '/images/logo.svg',
            'unoptimized' => true,
            'width' => 200,
            'height' => 50,
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('<img', $html);
        self::assertStringNotContainsString('<picture>', $html);
        self::assertStringContainsString('src="/images/logo.svg"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    public function testRenderPriorityImage(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
            'priority' => true,
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('loading="eager"', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
        self::assertStringNotContainsString('background-image', $html);
    }

    public function testRenderBlurPlaceholder(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
            'placeholder' => true,
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('background-image:url(', $html);
        self::assertStringContainsString('onload="this.style.backgroundImage=', $html);
    }

    public function testRenderWithExplicitLoader(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'pixel.gif',
            'loader' => 'secondary',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('srcset=', $html);
    }
}
