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

use InvalidArgumentException;

/**
 * The image reference passed to a loader cannot be served by it (e.g. an entity
 * without the loader's VichUploader mapping, or an ambiguous upload field).
 */
final class InvalidImageReferenceException extends InvalidArgumentException implements PicassoExceptionInterface
{
}
