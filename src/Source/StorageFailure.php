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

namespace Silarhi\PicassoBundle\Source;

use function in_array;
use function method_exists;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

/**
 * What a failed call to a Flysystem storage says about the storage.
 *
 * Flysystem exceptions carry no HTTP status: object store adapters keep the client
 * exception as previous. The first HTTP client exception of the chain tells whether
 * the server answered, and with which status:
 *
 * - a network error (PSR-18 NetworkExceptionInterface, Symfony HttpClient
 *   TransportExceptionInterface), or an exception whose getResponse() is null (AWS
 *   SDK, Guzzle: the request got no answer) is transient;
 * - an answer (PSR-7 response, Symfony HttpClient response) is transient when its
 *   status is 408, 425, 429 or 5xx.
 *
 * A chain without HTTP client exceptions (local disk, SFTP...) has no status and is
 * not transient: those failures keep their previous meaning.
 *
 * @internal
 */
final readonly class StorageFailure
{
    private const TRANSIENT_CLIENT_ERRORS = [408, 425, 429];

    private function __construct(
        /** HTTP status the storage answered; null when it did not answer, or does not speak HTTP */
        public ?int $status,
        public bool $transient,
    ) {
    }

    public static function of(Throwable $failure): self
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof NetworkExceptionInterface || $cause instanceof TransportExceptionInterface) {
                return new self(null, true);
            }

            if ($cause instanceof HttpExceptionInterface) {
                try {
                    return self::answered($cause->getResponse()->getStatusCode());
                } catch (TransportExceptionInterface) {
                    return new self(null, true);
                }
            }

            if (!method_exists($cause, 'getResponse')) {
                continue;
            }

            $response = $cause->getResponse();
            if (null === $response) {
                // An HTTP client exception without a response: the request never got an answer
                return new self(null, true);
            }

            if ($response instanceof ResponseInterface) {
                return self::answered($response->getStatusCode());
            }
        }

        return new self(null, false);
    }

    /**
     * The storage refused a write because of a concurrent one: e.g. an object
     * store answering 409 or 412 to the loser of two conditional uploads.
     */
    public function isConflict(): bool
    {
        return 409 === $this->status || 412 === $this->status;
    }

    private static function answered(int $status): self
    {
        return new self($status, $status >= 500 || in_array($status, self::TRANSIENT_CLIENT_ERRORS, true));
    }
}
