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

use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The 503 of an image whose source storage is unavailable.
 *
 * Never cacheable, whatever cache_control says: a CDN keeping it (as it may keep
 * a 404 for error_max_age) would go on failing the image after the outage.
 * Retry-After asks clients to come back once the storage had time to recover.
 *
 * @internal
 */
final class ServiceUnavailableExceptionFactory
{
    /**
     * Seconds clients are asked to wait before requesting the image again.
     */
    public const RETRY_AFTER = 30;

    public static function create(ImageSourceUnavailableException $previous): ServiceUnavailableHttpException
    {
        return new ServiceUnavailableHttpException(self::RETRY_AFTER, $previous->getMessage(), $previous, 0, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
