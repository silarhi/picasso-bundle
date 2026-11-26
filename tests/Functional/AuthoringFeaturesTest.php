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

use Silarhi\PicassoBundle\Exception\InvalidTransformationException;
use Silarhi\PicassoBundle\Exception\PresetNotFoundException;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
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
