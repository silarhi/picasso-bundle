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

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;
use Twig\Environment;

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
