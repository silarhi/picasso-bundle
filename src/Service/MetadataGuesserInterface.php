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

use Closure;

/**
 * @phpstan-type ImageGuessedMetadata array{width: int|null, height: int|null, mimeType: string|null}
 */
interface MetadataGuesserInterface
{
    /**
     * Guess image dimensions and MIME type from a stream.
     *
     * The stream can be provided eagerly (resource) or lazily (Closure returning a resource).
     * When a Closure is provided and the result is cached, the Closure is never invoked,
     * avoiding unnecessary I/O (e.g. Flysystem/URL loaders).
     *
     * @param resource|(Closure(): (resource|null)) $stream
     * @param string|null                           $identifier Optional stable identifier (e.g. image path) used as cache key
     *
     * @return ImageGuessedMetadata
     */
    public function guess(mixed $stream, ?string $identifier = null): array;
}
