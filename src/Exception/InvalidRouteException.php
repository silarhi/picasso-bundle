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
 * Image URLs cannot be generated for the requested route: a private loader
 * rendered without a route, a route with a transformer that cannot serve it,
 * or route parameters clashing with the transformation params.
 */
final class InvalidRouteException extends InvalidArgumentException implements PicassoExceptionInterface
{
}
