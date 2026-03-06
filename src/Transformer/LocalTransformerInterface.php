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

namespace Silarhi\PicassoBundle\Transformer;

use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A transformer that can serve images locally (e.g. Glide).
 *
 * The controller delegates serving to this interface, passing the loader
 * so the transformer can access the source filesystem.
 */
interface LocalTransformerInterface extends ImageTransformerInterface
{
    public function serve(ServableLoaderInterface $loader, string $path, Request $request): Response;
}
