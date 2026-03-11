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

final readonly class UrlLoader implements ImageLoaderInterface
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
    ) {
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
