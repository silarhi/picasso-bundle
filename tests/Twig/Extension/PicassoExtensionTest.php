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

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;

class PicassoExtensionTest extends TestCase
{
    public function testRegistersTwigFunction(): void
    {
        $helper = $this->createMock(ImageHelperInterface::class);
        $extension = new PicassoExtension($helper);

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('picasso_image_url', $functions[0]->getName());
    }

    public function testFunctionIsCallable(): void
    {
        $helper = $this->createMock(ImageHelperInterface::class);
        $extension = new PicassoExtension($helper);

        $functions = $extension->getFunctions();
        $callable = $functions[0]->getCallable();

        self::assertIsCallable($callable);
    }

    public function testFunctionDelegatesToImageHelper(): void
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
}
