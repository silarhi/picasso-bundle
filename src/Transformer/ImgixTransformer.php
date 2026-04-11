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

use function in_array;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Exception\PurgeException;

use function sprintf;

use Throwable;

/**
 * @phpstan-import-type TransformerContext from ImageTransformerInterface
 *
 * @see https://docs.imgix.com/apis/rendering
 */
final readonly class ImgixTransformer implements PurgableTransformerInterface
{
    private const PURGE_API_URL = 'https://api.imgix.com/api/v1/purge';

    public function __construct(
        private string $baseUrl,
        private ?string $signKey = null,
        private ?string $apiKey = null,
        private ?ClientInterface $httpClient = null,
        private ?RequestFactoryInterface $requestFactory = null,
        private ?StreamFactoryInterface $streamFactory = null,
    ) {
    }

    public function url(Image $image, ImageTransformation $transformation, array $context = []): string
    {
        $imgixParams = $this->mapToImgixParams($transformation);

        $path = '/' . ltrim($image->path ?? '', '/');
        $queryString = http_build_query($imgixParams);

        if (null !== $this->signKey) {
            $signature = $this->generateSignature($this->signKey, $path, $queryString);
            $queryString .= ('' !== $queryString ? '&' : '') . 's=' . $signature;
        }

        return rtrim($this->baseUrl, '/') . $path . ('' !== $queryString ? '?' . $queryString : '');
    }

    /**
     * @see https://docs.imgix.com/en-US/apis/management/purges
     */
    public function purge(string $path, array $context = []): void
    {
        if (null === $this->apiKey || '' === $this->apiKey) {
            throw new InvalidConfigurationException('Imgix purge requires an "api_key" to be configured.');
        }

        if (in_array(null, [$this->httpClient, $this->requestFactory, $this->streamFactory], true)) {
            throw new InvalidConfigurationException('Imgix purge requires "http_client", "request_factory", and "stream_factory" services to be configured.');
        }

        $imgixUrl = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        $body = json_encode([
            'data' => [
                'attributes' => [
                    'url' => $imgixUrl,
                ],
                'type' => 'purges',
            ],
        ], \JSON_THROW_ON_ERROR);

        $request = $this->requestFactory->createRequest('POST', self::PURGE_API_URL)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/vnd.api+json')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (Throwable $e) {
            throw new PurgeException(sprintf('Imgix purge request failed for "%s".', $path), $e->getCode(), previous: $e);
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new PurgeException(sprintf('Imgix purge API returned HTTP %d for "%s".', $statusCode, $path));
        }
    }

    /**
     * @return TransformerParams
     */
    private function mapToImgixParams(ImageTransformation $transformation): array
    {
        $imgix = [];

        if (null !== $transformation->width) {
            $imgix['w'] = $transformation->width;
        }
        if (null !== $transformation->height) {
            $imgix['h'] = $transformation->height;
        }
        if (null !== $transformation->format) {
            $imgix['fm'] = $transformation->format;
        }

        if (null !== $transformation->quality) {
            $imgix['q'] = $transformation->quality;
        }
        if (null !== $transformation->fit) {
            $imgix['fit'] = $this->mapFit($transformation->fit);
        }

        if (null !== $transformation->blur) {
            $imgix['blur'] = $transformation->blur;
        }
        if (null !== $transformation->dpr) {
            $imgix['dpr'] = $transformation->dpr;
        }

        return $imgix;
    }

    /**
     * @see https://docs.imgix.com/apis/rendering/size/resize-fit-mode
     */
    private function mapFit(string $fit): string
    {
        return match ($fit) {
            'contain' => 'clip',
            'cover', 'crop' => 'crop',
            'fill' => 'fill',
            default => $fit,
        };
    }

    /**
     * @see https://docs.imgix.com/setup/securing-images
     */
    private function generateSignature(string $signKey, string $path, string $queryString): string
    {
        $data = $signKey . $path;
        if ('' !== $queryString) {
            $data .= '?' . $queryString;
        }

        return md5($data);
    }
}
