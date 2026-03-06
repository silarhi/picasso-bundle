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

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class UrlLoader implements ImageLoaderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $url = $reference->path ?? '';
        if ('' === $url) {
            return new Image();
        }

        return new Image(
            url: $url,
            stream: fn () => StreamWrapper::createResource($this->httpClient->request('GET', $url), $this->httpClient),
        );
    }
}
