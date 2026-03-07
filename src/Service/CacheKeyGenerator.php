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

/**
 * Centralizes cache key generation for all Picasso cache entries.
 */
final class CacheKeyGenerator
{
    /**
     * Generate a cache key with the given namespace and arguments.
     *
     * @param string           $name      Cache namespace (e.g. 'metadata', 'blurhash')
     * @param list<string|int> $arguments Values that uniquely identify the cached entry
     */
    public static function generate(string $name, array $arguments): string
    {
        return 'picasso:' . $name . ':' . hash('xxh128', implode('|', array_map(strval(...), $arguments)));
    }
}
