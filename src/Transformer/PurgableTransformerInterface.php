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

use Silarhi\PicassoBundle\Exception\PurgeException;

/**
 * A transformer that supports purging cached image variants.
 *
 * @phpstan-import-type TransformerContext from ImageTransformerInterface
 */
interface PurgableTransformerInterface extends ImageTransformerInterface
{
    /**
     * Purge all cached variants of the given image path.
     *
     * @param TransformerContext $context Extra context (e.g. loader/transformer names for cache path resolution)
     *
     * @throws PurgeException When the purge operation fails
     */
    public function purge(string $path, array $context = []): void;
}
