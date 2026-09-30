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

use function sprintf;

/**
 * Reads images from a directory on the local filesystem.
 *
 * Paths that would escape the root directory (via "..") are treated as missing.
 */
final readonly class LocalImageSource implements ImageSourceInterface
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public function exists(string $path): bool
    {
        $absolutePath = $this->resolve($path);

        return null !== $absolutePath && is_file($absolutePath);
    }

    public function readStream(string $path)
    {
        $absolutePath = $this->resolve($path);

        if (null === $absolutePath || !is_file($absolutePath)) {
            throw new ImageNotFoundException(sprintf('Image "%s" not found.', $path));
        }

        $handle = @fopen($absolutePath, 'r');
        if (false === $handle) {
            throw new ImageNotFoundException(sprintf('Image "%s" could not be opened.', $path));
        }

        return $handle;
    }

    /**
     * Resolve a relative path to an absolute one inside the root, or null when
     * it is empty, contains a null byte or would escape the root.
     */
    private function resolve(string $path): ?string
    {
        if (str_contains($path, "\0")) {
            return null;
        }

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                if ([] === $segments) {
                    return null;
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        if ([] === $segments) {
            return null;
        }

        return $this->root . '/' . implode('/', $segments);
    }
}
