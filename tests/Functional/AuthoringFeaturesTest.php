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
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

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
}
