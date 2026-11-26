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

use function array_slice;
use function count;
use function in_array;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Exception\InvalidTransformationException;
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

    /**
     * Params Imgix reads from options of the transformation (width, format…),
     * never from its modifiers.
     */
    private const OPTION_PARAMS = ['w', 'h', 'fm', 'q', 'fit', 'crop', 'fp-x', 'fp-y', 'fp-z', 'blur', 'dpr', 's'];

    /**
     * Glide watermark modifiers and the Imgix params they become. "mark" names the
     * watermark image: an absolute URL for Imgix, a path in the watermarks storage for Glide.
     */
    private const MARK_PARAMS = [
        'mark' => 'mark',
        'markw' => 'mark-w',
        'markh' => 'mark-h',
        'markx' => 'mark-x',
        'marky' => 'mark-y',
        'markpad' => 'mark-pad',
        'markalpha' => 'mark-alpha',
        'markfit' => 'mark-fit',
    ];

    public function __construct(
        private string $baseUrl,
        #[SensitiveParameter] private ?string $signKey = null,
        #[SensitiveParameter] private ?string $apiKey = null,
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
            $imgix = [...$imgix, ...$this->mapFit($transformation->fit)];
        }

        if (null !== $transformation->blur) {
            $imgix['blur'] = $transformation->blur;
        }
        if (null !== $transformation->dpr) {
            $imgix['dpr'] = $transformation->dpr;
        }

        foreach ($transformation->modifiers as $key => $value) {
            $imgix = [...$imgix, ...$this->mapModifier($key, $value)];
        }

        return $imgix;
    }

    /**
     * Map a modifier, in Glide's vocabulary, to Imgix params. Other modifiers are Imgix params, passed through
     * unchanged (e.g. "auto" => "compress").
     *
     * @see https://glide.thephpleague.com/2.0/api/quick-reference/
     *
     * @return TransformerParams
     *
     * @throws InvalidTransformationException When the modifier is set by an option, or Imgix has no equivalent
     */
    private function mapModifier(string $key, int|string $value): array
    {
        if (isset(self::MARK_PARAMS[$key])) {
            return [self::MARK_PARAMS[$key] => $value];
        }

        $string = (string) $value;

        return match ($key) {
            // Glide rotates counter-clockwise; Imgix's degree aliases are Exif orientations, rotating clockwise
            'or' => match ($string) {
                'auto' => [],
                '0' => ['orient' => 1],
                '90' => ['orient' => 270],
                '180' => ['orient' => 180],
                '270' => ['orient' => 90],
                default => throw new InvalidTransformationException(sprintf('Invalid "or" modifier "%s": use "auto", "0", "90", "180" or "270".', $string)),
            },
            'flip' => ['flip' => 'both' === $string ? 'hv' : $string],
            // Glide: "width,height,x,y"; Imgix: "x,y,width,height"
            'crop' => ['rect' => $this->mapCropRectangle($string)],
            'pixel' => ['px' => $value],
            'filt' => match ($string) {
                'greyscale' => ['sat' => -100],
                'sepia' => ['sepia' => 100],
                default => throw new InvalidTransformationException(sprintf('The "filt" modifier "%s" has no Imgix equivalent: use "greyscale" or "sepia".', $string)),
            },
            // Glide: "width,color,method"; Imgix: "width,color"
            'border' => ['border' => implode(',', array_slice(explode(',', $string), 0, 2))],
            'markpos' => ['mark-align' => 'center' === $string ? 'middle,center' : str_replace('-', ',', $string)],
            'gam' => throw new InvalidTransformationException('The "gam" modifier has no Imgix equivalent: Glide\'s takes 0.1 to 9.99, Imgix\'s -100 to 100.'),
            default => in_array($key, self::OPTION_PARAMS, true)
                ? throw new InvalidTransformationException(sprintf('The "%s" modifier is set by its own option (width, height, format, quality, fit, blur, dpr): use the option instead.', $key)) : [$key => $value],
        };
    }

    /**
     * @throws InvalidTransformationException When the value is not "width,height,x,y"
     */
    private function mapCropRectangle(string $rectangle): string
    {
        $parts = explode(',', $rectangle);
        if (4 !== count($parts)) {
            throw new InvalidTransformationException(sprintf('Invalid "crop" modifier "%s": use "width,height,x,y".', $rectangle));
        }

        return implode(',', [$parts[2], $parts[3], $parts[0], $parts[1]]);
    }

    /**
     * Map a fit mode to Imgix params. Glide's crop positions ("crop-top-left") and focal points ("crop-25-75", in
     * percent of the width and height, with an optional zoom: "crop-25-75-2") become Imgix crop modes, so a template
     * keeps one fit value whatever the transformer.
     *
     * @see https://docs.imgix.com/apis/rendering/size/resize-fit-mode
     * @see https://docs.imgix.com/apis/rendering/size/crop
     *
     * @return array<string, string>
     */
    private function mapFit(string $fit): array
    {
        if (1 === preg_match('/^crop-(\d{1,3})-(\d{1,3})(?:-(\d{1,3}(?:\.\d+)?))?$/', $fit, $focalPoint)) {
            return [
                'fit' => 'crop',
                'crop' => 'focalpoint',
                'fp-x' => (string) ((int) $focalPoint[1] / 100),
                'fp-y' => (string) ((int) $focalPoint[2] / 100),
                ...(isset($focalPoint[3]) ? ['fp-z' => $focalPoint[3]] : []),
            ];
        }

        if (1 === preg_match('/^(?:crop|cover)-(top-left|top|top-right|left|center|right|bottom-left|bottom|bottom-right)$/', $fit, $position)) {
            // Imgix centers by default and takes the edges to keep: "top,left"
            return 'center' === $position[1]
                ? ['fit' => 'crop']
                : ['fit' => 'crop', 'crop' => str_replace('-', ',', $position[1])];
        }

        return ['fit' => match ($fit) {
            'contain' => 'clip',
            'cover', 'crop' => 'crop',
            'fill' => 'fill',
            default => $fit,
        }];
    }

    /**
     * @see https://docs.imgix.com/setup/securing-images
     */
    private function generateSignature(#[SensitiveParameter] string $signKey, string $path, string $queryString): string
    {
        $data = $signKey . $path;
        if ('' !== $queryString) {
            $data .= '?' . $queryString;
        }

        return md5($data);
    }
}
