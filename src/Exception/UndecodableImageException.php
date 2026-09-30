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
 * The source file exists but cannot be decoded as an image: a truncated upload,
 * or a non-image file (e.g. a PDF) stored under an image name.
 *
 * Deliberately distinct from {@see ImageNotFoundException}: the image controller
 * answers both with a 404, but only a missing source may be recovered from (for
 * instance by redirecting to the original file) — here the original is the
 * broken file itself.
 */
final class UndecodableImageException extends RuntimeException implements PicassoExceptionInterface
{
}
