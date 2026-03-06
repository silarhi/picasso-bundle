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
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Service\ImageHelper;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;

class PicassoExtensionTest extends TestCase
{
    public function testRegistersTwigFunction(): void
    {
        $helper = new ImageHelper($this->createMock(ImagePipeline::class), 75, 'contain');
        $extension = new PicassoExtension($helper);

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('picasso_image_url', $functions[0]->getName());
    }

    public function testFunctionIsCallable(): void
    {
        $helper = new ImageHelper($this->createMock(ImagePipeline::class), 75, 'contain');
        $extension = new PicassoExtension($helper);

        $functions = $extension->getFunctions();
        $callable = $functions[0]->getCallable();

        self::assertIsCallable($callable);
    }

    public function testFunctionDelegatesToImageHelper(): void
    {
        $pipeline = $this->createMock(ImagePipeline::class);
        $pipeline->expects(self::once())
            ->method('url')
            ->with(
                self::callback(static fn (ImageReference $ref): bool => '/images/photo.jpg' === $ref->path),
                self::isInstanceOf(ImageTransformation::class),
                null,
                null,
            )
            ->willReturn('/transformed/photo.jpg');

        $helper = new ImageHelper($pipeline, 75, 'contain');
        $extension = new PicassoExtension($helper);

        $functions = $extension->getFunctions();
        $callable = $functions[0]->getCallable();
        self::assertIsCallable($callable);

        /** @var string $result */
        $result = $callable('/images/photo.jpg');

        self::assertSame('/transformed/photo.jpg', $result);
    }
}
