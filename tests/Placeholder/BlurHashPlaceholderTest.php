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

namespace Silarhi\PicassoBundle\Tests\Placeholder;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Placeholder\BlurHashPlaceholder;

use function strlen;

class BlurHashPlaceholderTest extends TestCase
{
    public function testGenerateReturnsDataUri(): void
    {
        $placeholder = new BlurHashPlaceholder();

        $stream = $this->createTestImageStream(100, 75);
        $image = new Image(path: 'photo.jpg', stream: $stream);

        $result = $placeholder->generate($image, 100, 75);

        self::assertStringStartsWith('data:image/png;base64,', $result);
        self::assertNotSame('data:image/png;base64,', $result);
    }

    public function testGenerateWithCustomComponents(): void
    {
        $placeholder = new BlurHashPlaceholder(componentsX: 2, componentsY: 2, size: 16);

        $stream = $this->createTestImageStream(50, 50);
        $image = new Image(path: 'photo.jpg', stream: $stream);

        $result = $placeholder->generate($image, 50, 50);

        self::assertStringStartsWith('data:image/png;base64,', $result);
    }

    public function testGenerateWithLazyStream(): void
    {
        $placeholder = new BlurHashPlaceholder();

        $stream = $this->createTestImageStream(80, 60);
        $image = new Image(path: 'photo.jpg', stream: static fn () => $stream);

        $result = $placeholder->generate($image, 80, 60);

        self::assertStringStartsWith('data:image/png;base64,', $result);
    }

    public function testGenerateThrowsWithNullStream(): void
    {
        $placeholder = new BlurHashPlaceholder();
        $image = new Image(path: 'photo.jpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('image stream is not available');
        $placeholder->generate($image, 100, 100);
    }

    public function testGenerateProducesValidPng(): void
    {
        $placeholder = new BlurHashPlaceholder(size: 8);

        $stream = $this->createTestImageStream(200, 100);
        $image = new Image(path: 'photo.jpg', stream: $stream);

        $result = $placeholder->generate($image, 200, 100);

        $base64 = substr($result, strlen('data:image/png;base64,'));
        $binary = base64_decode($base64, true);
        self::assertNotFalse($binary);

        $gd = @imagecreatefromstring($binary);
        self::assertNotFalse($gd);
        // size=8, aspect 2:1 → 8×4
        self::assertSame(8, imagesx($gd));
        self::assertSame(4, imagesy($gd));
        imagedestroy($gd);
    }

    public function testGenerateWithLargeSourceImage(): void
    {
        $placeholder = new BlurHashPlaceholder();

        // Create a larger image (200x150) to trigger internal downscale
        $stream = $this->createTestImageStream(200, 150);
        $image = new Image(path: 'photo.jpg', stream: $stream);

        $result = $placeholder->generate($image, 200, 150);

        self::assertStringStartsWith('data:image/png;base64,', $result);
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     *
     * @return resource
     */
    private function createTestImageStream(int $width, int $height)
    {
        $gd = imagecreatetruecolor($width, $height);
        self::assertNotFalse($gd);

        // Fill with a gradient for non-trivial blurhash
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                /** @var int<0, 255> $r */
                $r = (int) (255 * $x / max(1, $width - 1));
                /** @var int<0, 255> $g */
                $g = (int) (255 * $y / max(1, $height - 1));
                $color = imagecolorallocate($gd, $r, $g, 128);
                self::assertNotFalse($color);
                imagesetpixel($gd, $x, $y, $color);
            }
        }

        $stream = fopen('php://temp', 'r+');
        self::assertNotFalse($stream);
        imagepng($gd, $stream);
        imagedestroy($gd);
        rewind($stream);

        return $stream;
    }
}
