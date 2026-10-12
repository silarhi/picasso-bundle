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

namespace Silarhi\PicassoBundle\Tests\Twig\Extension;

use function is_array;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Markup;

class PicassoExtensionTest extends TestCase
{
    public function testRegistersTwigFunctions(): void
    {
        $helper = $this->createMock(ImageHelperInterface::class);
        $extension = new PicassoExtension($helper);

        $functions = $extension->getFunctions();

        self::assertCount(2, $functions);
        self::assertSame('picasso_image_url', $functions[0]->getName());
        self::assertSame('picasso_image', $functions[1]->getName());
    }

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function provideUrlsToEscape(): iterable
    {
        yield 'srcset' => ['/image/glide/fs/cat.jpg?w=320&fm=webp&s=ab 320w, /image/glide/fs/cat.jpg?w=640&fm=webp&s=cd 640w', true];
        yield 'percent-encoded' => ['/image/glide/fs/%C3%A9t%C3%A9.jpg?fit=crop%2C50&s=ab', true];
        yield 'already escaped' => ['/a.jpg?w=1&amp;s=2', true];
        yield 'quote' => ['/a.jpg?alt="x"', false];
        yield 'apostrophe' => ["/l'été.jpg", false];
        yield 'tag' => ['/a.jpg?<script>', false];
        yield 'non-ascii' => ['/été.jpg', false];
        yield 'control character' => ["/a.jpg\n", false];
        yield 'null' => [null, false];
    }

    #[DataProvider('provideUrlsToEscape')]
    public function testEscapeUrlsGivesWhatTwigsEscaperGives(?string $urls, bool $fastPath): void
    {
        $env = new Environment(new ArrayLoader(['t' => '{{ urls|picasso_escape_urls }}']));
        $env->addExtension(new PicassoExtension(self::createStub(ImageHelperInterface::class)));

        $result = PicassoExtension::escapeUrls($env, $urls);

        self::assertSame($fastPath, $result instanceof Markup);
        self::assertSame(htmlspecialchars((string) $urls, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), $env->render('t', ['urls' => $urls]));
    }

    public function testEscapeUrlsLeavesOtherCharsetsToTwig(): void
    {
        $env = new Environment(new ArrayLoader(), ['charset' => 'ISO-8859-1']);

        self::assertSame('/a.jpg?w=1&s=2', PicassoExtension::escapeUrls($env, '/a.jpg?w=1&s=2'));
    }

    public function testPicassoImageUrlIsCallable(): void
    {
        $helper = $this->createMock(ImageHelperInterface::class);
        $extension = new PicassoExtension($helper);

        $functions = $extension->getFunctions();
        $callable = $functions[0]->getCallable();

        self::assertIsCallable($callable);
    }

    public function testPicassoImageUrlDelegatesToImageHelper(): void
    {
        $helper = $this->createMock(ImageHelperInterface::class);
        $helper->expects(self::once())
            ->method('imageUrl')
            ->with('/images/photo.jpg')
            ->willReturn('/transformed/photo.jpg');

        $extension = new PicassoExtension($helper);

        $functions = $extension->getFunctions();
        $callable = $functions[0]->getCallable();
        self::assertIsCallable($callable);

        /** @var string $result */
        $result = $callable('/images/photo.jpg');

        self::assertSame('/transformed/photo.jpg', $result);
    }

    public function testPicassoImageIsMarkedSafeHtml(): void
    {
        $helper = $this->createMock(ImageHelperInterface::class);
        $extension = new PicassoExtension($helper);

        $functions = $extension->getFunctions();

        self::assertTrue($functions[1]->needsEnvironment());
        self::assertSame(['html'], $functions[1]->getSafe(new \Twig\Node\Node()));
    }

    public function testPicassoImageDelegatesToImageHelperAndRendersTemplate(): void
    {
        $data = new ImageRenderData(
            fallbackSrc: '/img/hero.jpg',
            fallbackSrcset: '/img/hero.jpg 1x',
            sources: [],
            placeholderUri: null,
            width: 800,
            height: 600,
            loading: 'lazy',
            fetchPriority: null,
            sizes: '100vw',
            unoptimized: false,
        );

        $helper = $this->createMock(ImageHelperInterface::class);
        $helper->expects(self::once())
            ->method('imageData')
            ->willReturn($data);

        $env = $this->createMock(Environment::class);
        $env->expects(self::once())
            ->method('render')
            ->with('@Picasso/image.html.twig', self::callback(
                static fn (array $ctx): bool => ($ctx['data'] ?? null) === $data && is_array($ctx['attributes'] ?? null),
            ))
            ->willReturn('<picture>…</picture>');

        $extension = new PicassoExtension($helper);
        $result = $extension->renderImage($env, src: 'hero.jpg', width: 800, height: 600, sizes: '100vw');

        self::assertSame('<picture>…</picture>', $result);
    }
}
