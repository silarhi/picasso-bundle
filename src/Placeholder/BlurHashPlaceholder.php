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

use Imagine\Image\Box;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;

use function is_string;

use kornrunner\Blurhash\Blurhash;
use Psr\Cache\CacheItemPoolInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\ImageProcessingException;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Service\CacheKeyGenerator;

/**
 * @phpstan-import-type TransformerContext from \Silarhi\PicassoBundle\Transformer\ImageTransformerInterface
 */
final readonly class BlurHashPlaceholder implements PlaceholderInterface
{
    /**
     * @param int $componentsX Number of horizontal BlurHash components (1–9)
     * @param int $componentsY Number of vertical BlurHash components (1–9)
     * @param int $size        Width/height of the decoded placeholder image in pixels
     */
    public function __construct(
        private ImagineInterface $imagine,
        private int $componentsX = 4,
        private int $componentsY = 3,
        private int $size = 32,
        private ?CacheItemPoolInterface $cache = null,
    ) {
    }

    public function generate(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        if (!class_exists(Blurhash::class)) {
            throw new InvalidConfigurationException('The "kornrunner/blurhash" package is required for the BlurHash placeholder. Install it with: composer require kornrunner/blurhash');
        }

        if ($this->cache instanceof CacheItemPoolInterface && null !== $image->path) {
            // Loader name is optional for BlurHash — used only for cache key specificity
            $loader = isset($context['loader']) && is_string($context['loader']) ? $context['loader'] : '';
            $cacheKey = CacheKeyGenerator::generate('blurhash', [
                $loader,
                $image->path,
                $transformation->width ?? 0,
                $transformation->height ?? 0,
                $this->componentsX,
                $this->componentsY,
                $this->size,
            ]);
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
                /** @var string $cached */
                $cached = $item->get();

                return $cached;
            }

            $result = $this->doGenerate($image, $transformation);
            $item->set($result);
            $this->cache->save($item);

            return $result;
        }

        return $this->doGenerate($image, $transformation);
    }

    private function doGenerate(Image $image, ImageTransformation $transformation): string
    {
        $stream = $image->resolveStream();
        if (null === $stream) {
            throw new ImageProcessingException('Cannot generate BlurHash: image stream is not available.');
        }

        $pixels = $this->extractPixels($stream);

        $hash = Blurhash::encode($pixels, $this->componentsX, $this->componentsY);

        return $this->decodeToDataUri($hash, $transformation->width ?? 0, $transformation->height ?? 0);
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
            throw new ImageProcessingException('Cannot read image stream for BlurHash encoding.');
        }

        $img = $this->imagine->load($contents);
        $size = $img->getSize();
        $w = $size->getWidth();
        $h = $size->getHeight();

        // Resize to a small image to speed up encoding
        $maxDim = 64;
        if ($w > $maxDim || $h > $maxDim) {
            $ratio = min($maxDim / $w, $maxDim / $h);
            $newW = max(1, (int) round($w * $ratio));
            $newH = max(1, (int) round($h * $ratio));
            $img = $img->resize(new Box($newW, $newH));
            $w = $newW;
            $h = $newH;
        }

        $pixels = [];
        for ($y = 0; $y < $h; ++$y) {
            $row = [];
            for ($x = 0; $x < $w; ++$x) {
                $color = $img->getColorAt(new Point($x, $y));
                $row[] = [
                    (int) $color->getValue(ColorInterface::COLOR_RED),
                    (int) $color->getValue(ColorInterface::COLOR_GREEN),
                    (int) $color->getValue(ColorInterface::COLOR_BLUE),
                ];
            }
            $pixels[] = $row;
        }

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

        $palette = new RGB();
        $img = $this->imagine->create(new Box($decodeWidth, $decodeHeight));

        for ($y = 0; $y < $decodeHeight; ++$y) {
            for ($x = 0; $x < $decodeWidth; ++$x) {
                [$r, $g, $b] = $pixels[$y][$x];
                /** @var int<0, 255> $cr */
                $cr = max(0, min(255, $r));
                /** @var int<0, 255> $cg */
                $cg = max(0, min(255, $g));
                /** @var int<0, 255> $cb */
                $cb = max(0, min(255, $b));
                $img->draw()->dot(new Point($x, $y), $palette->color([$cr, $cg, $cb]));
            }
        }

        $data = $img->get('png');

        return 'data:image/png;base64,' . base64_encode($data);
    }
}
