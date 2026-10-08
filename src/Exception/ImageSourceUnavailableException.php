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

namespace Silarhi\PicassoBundle\Exception;

use RuntimeException;

/**
 * The storage holding the source image could not be reached, or answered with a
 * transient error (5xx, 429, a timeout, a dropped connection): the image may well
 * exist, it just cannot be read right now.
 *
 * Deliberately distinct from {@see ImageNotFoundException}: the image controller
 * answers a 503 that no cache may keep, where a 404 may be cached (error_max_age)
 * and would outlive the outage.
 */
final class ImageSourceUnavailableException extends RuntimeException implements PicassoExceptionInterface
{
}
