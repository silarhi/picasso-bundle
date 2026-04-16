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

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Verifies that the picasso_image() Twig function renders the same HTML as the
 * <Picasso:Image> component, so consumers without symfony/ux-twig-component can
 * still render responsive <picture> elements.
 */
class PicassoImageFunctionTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return FullConfigKernel::class;
    }

    public function testRendersPictureElement(): void
    {
        $html = $this->renderInline("{{ picasso_image(src='photo.jpg', sizes='100vw') }}");

        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('src="/image/local_glide/main/photo.jpg', $html);
        self::assertStringContainsString('sizes="100vw"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertMatchesRegularExpression('/<source[^>]+type="image\/webp"/s', $html);
    }

    public function testForwardsExtraAttributes(): void
    {
        $html = $this->renderInline(<<<'TWIG'
            {{ picasso_image(
                src='photo.jpg',
                sizes='100vw',
                attributes={alt: 'A photo', class: 'rounded shadow-lg', id: 'main'}
            ) }}
            TWIG);

        self::assertStringContainsString('alt="A photo"', $html);
        self::assertStringContainsString('class="rounded shadow-lg"', $html);
        self::assertStringContainsString('id="main"', $html);
    }

    public function testPriorityTogglesEagerAndFetchpriority(): void
    {
        $html = $this->renderInline("{{ picasso_image(src='photo.jpg', sizes='100vw', priority=true) }}");

        self::assertStringContainsString('loading="eager"', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
        // Priority disables placeholder
        self::assertStringNotContainsString('background-image:url(', $html);
    }

    public function testUnoptimizedRendersPlainImg(): void
    {
        $html = $this->renderInline("{{ picasso_image(src='/logo.svg', unoptimized=true) }}");

        self::assertStringNotContainsString('<picture>', $html);
        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('src="/logo.svg"', $html);
    }

    public function testFunctionOutputIsNotHtmlEscaped(): void
    {
        $html = $this->renderInline("{{ picasso_image(src='photo.jpg', sizes='100vw') }}");

        // If the function wasn't marked is_safe: ['html'], the angle brackets
        // would be encoded as &lt;picture&gt; instead of rendered as HTML.
        self::assertStringNotContainsString('&lt;picture&gt;', $html);
        self::assertStringContainsString('<picture>', $html);
    }

    private function renderInline(string $template): string
    {
        $kernel = self::bootKernel();
        $container = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $twig = $container->get('twig');
        assert($twig instanceof Environment);

        return $twig->createTemplate($template)->render();
    }
}
