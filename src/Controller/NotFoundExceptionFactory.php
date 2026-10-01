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

namespace Silarhi\PicassoBundle\Controller;

use function sprintf;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The 404s of the bundle controllers, so they all follow cache_control.error_max_age.
 *
 * @internal
 */
final readonly class NotFoundExceptionFactory
{
    /**
     * @param int|null $maxAge Seconds clients and CDNs may keep a 404; null keeps it uncacheable
     */
    public function __construct(
        private ?int $maxAge = null,
    ) {
    }

    /**
     * A 404 that clients and CDNs may keep for the configured max age, so repeated
     * requests for a missing image stop reaching the application. Without one, it
     * stays uncacheable.
     */
    public function create(string $message, ?Throwable $previous = null): NotFoundHttpException
    {
        $headers = null !== $this->maxAge
            ? ['Cache-Control' => sprintf('public, max-age=%d', $this->maxAge)]
            : [];

        return new NotFoundHttpException($message, $previous, 0, $headers);
    }
}
