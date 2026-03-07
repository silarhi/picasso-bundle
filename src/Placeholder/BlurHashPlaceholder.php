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

namespace Silarhi\PicassoBundle\Placeholder;

use kornrunner\Blurhash\Blurhash;
use LogicException;
use RuntimeException;
use Silarhi\PicassoBundle\Dto\Image;

final readonly class BlurHashPlaceholder implements PlaceholderInterface
{
    /**
     * @param int $componentsX Number of horizontal BlurHash components (1–9)
     * @param int $componentsY Number of vertical BlurHash components (1–9)
     * @param int $size        Width/height of the decoded placeholder image in pixels
     */
    public function __construct(
        private int $componentsX = 4,
        private int $componentsY = 3,
        private int $size = 32,
    ) {
    }

    public function generate(Image $image, int $width, int $height, array $context = []): string
    {
        if (!class_exists(Blurhash::class)) {
            throw new LogicException('The "kornrunner/blurhash" package is required for the BlurHash placeholder. Install it with: composer require kornrunner/blurhash');
        }

        $stream = $image->resolveStream();
        if (null === $stream) {
            throw new RuntimeException('Cannot generate BlurHash: image stream is not available.');
        }

        $pixels = $this->extractPixels($stream);

        $hash = Blurhash::encode($pixels, $this->componentsX, $this->componentsY);

        return $this->decodeToDataUri($hash, $width, $height);
    }

    /**
     * @param resource $stream
     *
     * @return list<list<array{int, int, int}>>
     */
    private function extractPixels($stream): array
    {
        $contents = stream_get_contents($stream);
        if (false === $contents || '' === $contents) {
            throw new RuntimeException('Cannot read image stream for BlurHash encoding.');
        }

        $gd = @imagecreatefromstring($contents);
        if (false === $gd) {
            throw new RuntimeException('Cannot create GD image from stream for BlurHash encoding.');
        }

        $w = imagesx($gd);
        $h = imagesy($gd);

        // Resize to a small image to speed up encoding
        $maxDim = 64;
        if ($w > $maxDim || $h > $maxDim) {
            $ratio = min($maxDim / $w, $maxDim / $h);
            $newW = max(1, (int) round($w * $ratio));
            $newH = max(1, (int) round($h * $ratio));
            $resized = imagecreatetruecolor($newW, $newH);
            if (false === $resized) {
                imagedestroy($gd);
                throw new RuntimeException('Cannot create resized GD image for BlurHash encoding.');
            }
            imagecopyresampled($resized, $gd, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($gd);
            $gd = $resized;
            $w = $newW;
            $h = $newH;
        }

        $pixels = [];
        for ($y = 0; $y < $h; ++$y) {
            $row = [];
            for ($x = 0; $x < $w; ++$x) {
                $rgb = imagecolorat($gd, $x, $y);
                if (false === $rgb) {
                    $row[] = [0, 0, 0];
                    continue;
                }
                $row[] = [
                    ($rgb >> 16) & 0xFF,
                    ($rgb >> 8) & 0xFF,
                    $rgb & 0xFF,
                ];
            }
            $pixels[] = $row;
        }

        imagedestroy($gd);

        return $pixels;
    }

    private function decodeToDataUri(string $hash, int $width, int $height): string
    {
        /** @var int<1, max> $decodeWidth */
        $decodeWidth = max(1, $this->size);
        /** @var int<1, max> $decodeHeight */
        $decodeHeight = max(1, $this->size);

        if ($width > 0) {
            /** @var int<1, max> $decodeHeight */
            $decodeHeight = max(1, (int) round($decodeWidth * $height / $width));
        }

        /** @var list<list<array{int, int, int}>> $pixels */
        $pixels = Blurhash::decode($hash, $decodeWidth, $decodeHeight);

        $gd = imagecreatetruecolor($decodeWidth, $decodeHeight);
        if (false === $gd) {
            throw new RuntimeException('Cannot create GD image for BlurHash decoding.');
        }

        for ($y = 0; $y < $decodeHeight; ++$y) {
            for ($x = 0; $x < $decodeWidth; ++$x) {
                [$r, $g, $b] = $pixels[$y][$x];
                /** @var int<0, 255> $cr */
                $cr = max(0, min(255, $r));
                /** @var int<0, 255> $cg */
                $cg = max(0, min(255, $g));
                /** @var int<0, 255> $cb */
                $cb = max(0, min(255, $b));
                $color = imagecolorallocate($gd, $cr, $cg, $cb);
                if (false !== $color) {
                    imagesetpixel($gd, $x, $y, $color);
                }
            }
        }

        ob_start();
        imagepng($gd);
        $data = ob_get_clean();
        imagedestroy($gd);

        if (false === $data || '' === $data) {
            throw new RuntimeException('Failed to encode BlurHash placeholder as PNG.');
        }

        return 'data:image/png;base64,' . base64_encode($data);
    }
}
