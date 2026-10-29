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

use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Without Twig, the bundle still generates and serves images; only the Twig function and component are missing.
 */
class NoTwigTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return NoTwigKernel::class;
    }

    public function testTwigServicesAreNotRegistered(): void
    {
        $container = self::getContainer();

        self::assertFalse($container->has('.picasso.twig_extension'));
        self::assertFalse($container->has('.picasso.image_component'));
    }

    public function testImageUrlIsGeneratedAndServed(): void
    {
        $helper = self::getContainer()->get('picasso.image_helper');
        self::assertInstanceOf(ImageHelperInterface::class, $helper);

        $url = $helper->imageUrl('photo.jpg', width: 40, format: 'webp');
        self::assertStringStartsWith('/image/glide/filesystem/photo.jpg?', $url);

        $response = self::$kernel?->handle(Request::create($url));

        self::assertSame(200, $response?->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
    }
}
