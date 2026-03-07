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

namespace Silarhi\PicassoBundle\Transformer;

use function dirname;

use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;

use function is_string;

use JsonException;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\EncryptionException;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlEncryption;

use function sprintf;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ImagineTransformer implements LocalTransformerInterface
{
    public function __construct(
        private UrlGeneratorInterface $router,
        private UrlEncryption $urlEncryption,
        private string $signKey,
        private string $cache,
        private string $driver = 'gd',
    ) {
    }

    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $path = $image->path ?? '';
        /** @var string $loaderName */
        $loaderName = $context['loader'] ?? throw new LoaderNotFoundException('The "loader" key is required in the context array.');
        /** @var string $transformerName */
        $transformerName = $context['transformer'] ?? throw new TransformerNotFoundException('The "transformer" key is required in the context array.');

        $params = $this->mapToParams($transformation);

        if ([] !== $image->metadata) {
            $params['_metadata'] = $this->urlEncryption->encrypt(json_encode($image->metadata, \JSON_THROW_ON_ERROR));
        }

        $params['s'] = $this->generateSignature($path, $params);

        return $this->router->generate('picasso_image', [
            'transformer' => $transformerName,
            'loader' => $loaderName,
            'path' => $path,
            ...$params,
        ], UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    public function serve(ServableLoaderInterface $loader, string $path, Request $request, array $context = []): Response
    {
        /** @var array<string, string> $params */
        $params = $request->query->all();

        if (!$this->validateSignature($path, $params)) {
            throw new ImageNotFoundException('Invalid image signature.');
        }

        if (isset($params['_metadata'])) {
            try {
                /** @var string $encryptedMetadata */
                $encryptedMetadata = $params['_metadata'];
                unset($params['_metadata']);
                $metadata = json_decode($this->urlEncryption->decrypt($encryptedMetadata), true, flags: \JSON_THROW_ON_ERROR);
            } catch (EncryptionException|JsonException $e) {
                throw new ImageNotFoundException('Invalid metadata parameter.', previous: $e);
            }
        } else {
            $metadata = [];
        }

        /** @var array<string, mixed> $metadata */
        $source = $loader->getSource($metadata);

        if (!is_string($source)) {
            throw new ImageNotFoundException('Imagine transformer only supports filesystem loaders.');
        }

        $sourcePath = rtrim($source, '/') . '/' . $path;

        if (!is_file($sourcePath)) {
            throw new ImageNotFoundException(sprintf('Image "%s" not found.', $path));
        }

        $transformation = $this->paramsToTransformation($params);
        $cacheKey = $this->buildCacheKey($path, $params);
        $format = $transformation->format ?? pathinfo($path, \PATHINFO_EXTENSION) ?: 'jpg';
        $cachePath = $this->cache . '/' . $cacheKey . '.' . $format;

        if (!is_file($cachePath)) {
            $this->transform($sourcePath, $cachePath, $transformation, $format);
        }

        return new BinaryFileResponse($cachePath, headers: [
            'Content-Type' => $this->formatToMimeType($format),
        ]);
    }

    private function transform(string $sourcePath, string $cachePath, ImageTransformation $transformation, string $format): void
    {
        $imagine = $this->createImagine();
        $image = $imagine->open($sourcePath);

        $width = $transformation->width;
        $height = $transformation->height;

        if (null !== $width || null !== $height) {
            $image = $this->applyResize($image, $width, $height, $transformation->fit);
        }

        if (null !== $transformation->blur && $transformation->blur > 0) {
            $sigma = (float) $transformation->blur;
            $image->effects()->blur($sigma);
        }

        (new Filesystem())->mkdir(dirname($cachePath));

        $image->save($cachePath, $this->buildSaveOptions($format, $transformation->quality));
    }

    private function applyResize(ImageInterface $image, ?int $width, ?int $height, string $fit): ImageInterface
    {
        $currentSize = $image->getSize();
        $currentWidth = $currentSize->getWidth();
        $currentHeight = $currentSize->getHeight();

        $targetWidth = $width ?? $currentWidth;
        $targetHeight = $height ?? $currentHeight;

        return match ($fit) {
            'crop', 'cover' => $image->thumbnail(new Box($targetWidth, $targetHeight), ImageInterface::THUMBNAIL_OUTBOUND),
            'fill' => $image->resize(new Box($targetWidth, $targetHeight)),
            default => $image->thumbnail(new Box($targetWidth, $targetHeight), ImageInterface::THUMBNAIL_INSET),
        };
    }

    private function createImagine(): ImagineInterface
    {
        return match ($this->driver) {
            'imagick' => new \Imagine\Imagick\Imagine(),
            default => new \Imagine\Gd\Imagine(),
        };
    }

    /**
     * @return array<string, int|string>
     */
    private function mapToParams(ImageTransformation $transformation): array
    {
        $params = [];

        if (null !== $transformation->width) {
            $params['w'] = $transformation->width;
        }
        if (null !== $transformation->height) {
            $params['h'] = $transformation->height;
        }
        if (null !== $transformation->format) {
            $params['fm'] = $transformation->format;
        }

        $params['q'] = $transformation->quality;
        $params['fit'] = $transformation->fit;

        if (null !== $transformation->blur) {
            $params['blur'] = $transformation->blur;
        }
        if (null !== $transformation->dpr) {
            $params['dpr'] = $transformation->dpr;
        }

        return $params;
    }

    /**
     * @param array<string, string> $params
     */
    private function paramsToTransformation(array $params): ImageTransformation
    {
        return new ImageTransformation(
            width: isset($params['w']) ? (int) $params['w'] : null,
            height: isset($params['h']) ? (int) $params['h'] : null,
            format: $params['fm'] ?? null,
            quality: isset($params['q']) ? (int) $params['q'] : 75,
            fit: $params['fit'] ?? 'contain',
            blur: isset($params['blur']) ? (int) $params['blur'] : null,
            dpr: isset($params['dpr']) ? (int) $params['dpr'] : null,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function generateSignature(string $path, array $params): string
    {
        ksort($params);

        return hash_hmac('sha256', $path . '?' . http_build_query($params), $this->signKey);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateSignature(string $path, array $params): bool
    {
        /** @var string $signature */
        $signature = $params['s'] ?? '';
        unset($params['s']);
        $expected = $this->generateSignature($path, $params);

        return hash_equals($expected, $signature);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildCacheKey(string $path, array $params): string
    {
        unset($params['s'], $params['_metadata']);
        ksort($params);

        return md5($path . '|' . http_build_query($params));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSaveOptions(string $format, int $quality): array
    {
        return match ($format) {
            'png' => ['png_compression_level' => (int) round(9 * (100 - $quality) / 100)],
            'webp' => ['webp_quality' => $quality],
            'avif' => ['avif_quality' => $quality],
            default => ['jpeg_quality' => $quality],
        };
    }

    private function formatToMimeType(string $format): string
    {
        return match ($format) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'image/jpeg',
        };
    }
}
