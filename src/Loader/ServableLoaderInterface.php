<?php

namespace Silarhi\PicassoBundle\Loader;

/**
 * A loader that can provide its source filesystem for local transformers.
 */
interface ServableLoaderInterface extends ImageLoaderInterface
{
    /**
     * Get the filesystem source for serving images.
     *
     * @return object|string Local path (string) or FilesystemOperator
     */
    public function getSource(): object|string;
}
