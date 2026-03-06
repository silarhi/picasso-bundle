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

namespace Silarhi\PicassoBundle\Service;

interface MetadataGuesserInterface
{
    /**
     * Guess image dimensions and MIME type from a stream.
     *
     * @param resource $stream
     *
     * @return array{width: int|null, height: int|null, mimeType: string|null}
     */
    public function guess($stream): array;
}
