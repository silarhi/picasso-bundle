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

namespace Silarhi\PicassoBundle\Loader;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Source\HttpImageSource;
use Silarhi\PicassoBundle\Source\ImageSourceInterface;

/**
 * Loads remote images by absolute URL. Local transformers (Glide) fetch them
 * from the allowed hosts only; rendering and other transformers (Imgix) are not
 * restricted, the latter fetching the image themselves.
 */
final readonly class UrlLoader implements ServableLoaderInterface
{
    private HttpImageSource $source;

    /**
     * @param list<string> $allowedHosts Hosts local transformers may fetch images from: "example.com", or
     *                                   "*.example.com" for its subdomains. Empty allows any host.
     */
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        array $allowedHosts = [],
    ) {
        $this->source = new HttpImageSource($httpClient, $requestFactory, $allowedHosts);
    }

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $url = $reference->path ?? '';
        if ('' === $url) {
            return new Image();
        }

        return new Image(
            path: $url,
            stream: fn () => $this->getRequestStream($url),
        );
    }

    public function getSource(): ImageSourceInterface
    {
        return $this->source;
    }

    /**
     * @return resource|null
     */
    private function getRequestStream(string $url)
    {
        $request = $this->requestFactory->createRequest('GET', $url);
        $response = $this->httpClient->sendRequest($request);

        return $response->getBody()->detach();
    }
}
