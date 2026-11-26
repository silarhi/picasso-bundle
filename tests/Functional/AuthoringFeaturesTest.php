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

use Psr\Link\LinkInterface;
use Silarhi\PicassoBundle\Exception\InvalidTransformationException;
use Silarhi\PicassoBundle\Exception\PresetNotFoundException;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\WebLink\GenericLinkProvider;
use Twig\Environment;

class AuthoringFeaturesTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return AuthoringKernel::class;
    }

    protected function setUp(): void
    {
        (new Filesystem())->remove(self::bootKernel()->getCacheDir() . '/glide');
        self::ensureKernelShutdown();
    }

    // --- Presets ---

    public function testPresetFillsTheOptionsNotGiven(): void
    {
        $html = $this->render("{{ picasso_image(src='photo.jpg', preset='card', attributes={alt: 'Card'}) }}");

        self::assertStringContainsString('width="40" height="30"', $html);
        self::assertMatchesRegularExpression('#srcset="/image/glide/filesystem/photo\.jpg\?w=40&amp;h=30&amp;fm=webp&amp;q=60&amp;fit=cover&amp;sharp=5&amp;s=#', $html);
    }

    public function testExplicitOptionsWinOverThePreset(): void
    {
        $url = $this->helper()->imageUrl('photo.jpg', width: 20, modifiers: ['flip' => 'h', 'sharp' => 9], preset: 'card');

        self::assertStringStartsWith('/image/glide/filesystem/photo.jpg?w=20&h=30&q=60&fit=cover&sharp=9&flip=h&s=', $url);
    }

    public function testPresetPicksTheTransformer(): void
    {
        self::assertSame('https://test.imgix.net/photo.jpg?w=40&q=75&fit=clip', $this->helper()->imageUrl('photo.jpg', preset: 'remote'));
    }

    public function testComponentTakesAPreset(): void
    {
        $html = $this->render('<twig:Picasso:Image src="photo.jpg" preset="hero" alt="Hero" />');

        self::assertStringContainsString('sizes="100vw"', $html);
        self::assertStringNotContainsString('background-image', $html, 'the preset disables the placeholder');
    }

    public function testUnknownPresetFails(): void
    {
        $this->expectException(PresetNotFoundException::class);
        $this->expectExceptionMessage('Preset "nope" not found. Available: "card", "hero", "square", "remote".');

        $this->helper()->imageUrl('photo.jpg', preset: 'nope');
    }

    // --- Modifiers ---

    public function testModifierWithItsOwnOptionFails(): void
    {
        $this->expectException(InvalidTransformationException::class);
        $this->expectExceptionMessage('The "w" modifier is set by its own option');

        $this->helper()->imageUrl('photo.jpg', modifiers: ['w' => 10]);
    }

    public function testUnknownModifierFails(): void
    {
        $this->expectException(InvalidTransformationException::class);
        $this->expectExceptionMessage('Unknown Glide modifier "flop"');

        $this->helper()->imageUrl('photo.jpg', modifiers: ['flop' => 'h']);
    }

    public function testServesModifiedAndWatermarkedImages(): void
    {
        $kernel = self::bootKernel();
        $url = $this->helper()->imageUrl('photo.jpg', width: 50, format: 'png', modifiers: ['flip' => 'h', 'mark' => 'pixel.gif', 'markpos' => 'top-left']);

        $response = $kernel->handle(Request::create($url));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
    }

    // --- Art direction ---

    public function testArtDirectedSourcesComeFirst(): void
    {
        $html = $this->render(<<<'TWIG'
            {{ picasso_image(src='photo.jpg', sizes='100vw', artDirection={
                '(max-width: 640px)': {width: 30, height: 30, fit: 'crop-50-50'},
            }) }}
            TWIG);

        self::assertMatchesRegularExpression(
            // Responsive candidates are the configured widths, capped to the largest square the 100x50 source holds
            '#<source\s+media="\(max-width: 640px\)"\s+type="image/webp"\s+srcset="/image/glide/filesystem/photo\.jpg\?w=48&amp;h=48&amp;fm=webp&amp;q=75&amp;fit=crop-50-50&amp;s=[^"]+ 48w, /image/glide/filesystem/photo\.jpg\?w=50&amp;h=50&amp;[^"]+ 50w"\s+sizes="100vw"\s+width="30" height="30"\s*/>'
            . '\s*<source\s+media="\(max-width: 640px\)"\s+type="image/jpeg"[^>]+/>'
            . '\s*<source\s+type="image/webp"#',
            $html,
        );
    }

    public function testArtDirectionTakesAPreset(): void
    {
        $data = $this->helper()->imageData(src: 'photo.jpg', width: 100, artDirection: ['(orientation: portrait)' => ['preset' => 'square']]);

        self::assertSame('(orientation: portrait)', $data->sources[0]->media);
        self::assertSame(30, $data->sources[0]->width);
        self::assertSame(30, $data->sources[0]->height);
        self::assertStringContainsString('fit=crop-50-50', $data->sources[0]->srcset);
        self::assertNull($data->sources[2]->media, 'the image\'s own sources follow');
    }

    public function testArtDirectionInheritsTheImageDimensions(): void
    {
        $data = $this->helper()->imageData(src: 'photo.jpg', width: 40, height: 20, artDirection: ['(max-width: 640px)' => ['modifiers' => ['flip' => 'v']]]);

        self::assertSame(40, $data->sources[0]->width);
        self::assertSame(20, $data->sources[0]->height);
        self::assertStringContainsString('flip=v', $data->sources[0]->srcset);
        self::assertStringNotContainsString('flip=v', $data->sources[2]->srcset);
    }

    public function testUnknownArtDirectionOptionFails(): void
    {
        $this->expectException(InvalidTransformationException::class);
        $this->expectExceptionMessage('Art direction "(max-width: 640px)": unknown option "widht".');

        $this->helper()->imageData(src: 'photo.jpg', artDirection: ['(max-width: 640px)' => ['widht' => 30]]);
    }

    public function testPriorityArtDirectionPreloadsEachMediaQuery(): void
    {
        self::bootKernel();
        $request = Request::create('/');
        $requestStack = self::getContainer()->get('request_stack');
        assert($requestStack instanceof RequestStack);
        $requestStack->push($request);

        $this->helper()->imageData(src: 'photo.jpg', sizes: '100vw', priority: true, artDirection: ['(max-width: 640px)' => ['width' => 30, 'height' => 30]]);

        $links = $request->attributes->get('_links');
        assert($links instanceof GenericLinkProvider);
        $media = [];
        foreach ($links->getLinks() as $link) {
            assert($link instanceof LinkInterface);
            $media[] = $link->getAttributes()['media'] ?? null;
        }
        self::assertSame(['(max-width: 640px)', 'not all and (max-width: 640px)'], $media);
    }

    // --- Twig functions ---

    public function testImageDataFunction(): void
    {
        self::assertSame('40x20', $this->render("{% set image = picasso_image_data(src='photo.jpg', width=40) %}{{ image.width }}x{{ image.height }}"));
    }

    private function helper(): ImageHelperInterface
    {
        $helper = self::getContainer()->get('picasso.image_helper');
        assert($helper instanceof ImageHelperInterface);

        return $helper;
    }

    private function render(string $template): string
    {
        $twig = self::getContainer()->get('twig');
        assert($twig instanceof Environment);

        return $twig->createTemplate($template)->render();
    }
}
