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

namespace Silarhi\PicassoBundle\Source;

use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;

/**
 * Read access to the original images behind a servable loader.
 *
 * Paths are relative to the source root. Local transformers (e.g. Glide) read
 * originals through this contract, so loaders never have to know which
 * storage library a transformer is built on.
 */
interface ImageSourceInterface
{
    /**
     * Whether a file exists at the given path.
     *
     * @throws ImageSourceUnavailableException When the source cannot tell right now (unreachable storage, transient error)
     */
    public function exists(string $path): bool;

    /**
     * Open the file at the given path for reading.
     *
     * @return resource
     *
     * @throws ImageNotFoundException          When the file is missing or cannot be read
     * @throws ImageSourceUnavailableException When the source cannot be read right now (unreachable storage, transient
     *                                         error): the file may exist
     */
    public function readStream(string $path);
}
