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

use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;
use Silarhi\PicassoBundle\Exception\UndecodableImageException;
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
    /**
     * @param array<string, string> $context
     *
     * @throws ImageNotFoundException          When the source is missing or the signature is invalid (served as a 404)
     * @throws UndecodableImageException       When the source is not a decodable image (served as a 404)
     * @throws ImageSourceUnavailableException When the source cannot be read right now (served as an uncacheable 503)
     */
    public function serve(ServableLoaderInterface $loader, string $path, Request $request, array $context = []): Response;
}
