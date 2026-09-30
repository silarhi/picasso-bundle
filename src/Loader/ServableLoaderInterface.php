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

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Source\ImageSourceInterface;

/**
 * A loader that can provide read access to its original images for local transformers.
 */
interface ServableLoaderInterface extends ImageLoaderInterface
{
    /**
     * Get the source the original images are read from when serving them.
     *
     * A servable loader reads from exactly one source: the loader name in a
     * served image's URL is all that is needed to find the original again.
     */
    public function getSource(): ImageSourceInterface;
}
