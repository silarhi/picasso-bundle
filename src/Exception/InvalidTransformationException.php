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
 * A transformation cannot be expressed by the transformer: an unknown modifier,
 * a modifier naming a param that has its own option (width, format…), or a
 * value the transformer cannot encode in its URLs.
 */
final class InvalidTransformationException extends InvalidArgumentException implements PicassoExceptionInterface
{
}
