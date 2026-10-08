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

namespace Silarhi\PicassoBundle\Tests\Source\Stub;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function sprintf;

/**
 * What object store clients (AWS SDK, Guzzle) throw, and Flysystem adapters keep as
 * previous: the storage's response, or null when the request got no answer.
 */
final class ObjectStoreException extends RuntimeException
{
    public function __construct(
        private readonly ?ResponseInterface $response,
    ) {
        parent::__construct($response instanceof ResponseInterface ? sprintf('Unexpected status code %d.', $response->getStatusCode()) : 'Failed to open stream: HTTP request failed!');
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
