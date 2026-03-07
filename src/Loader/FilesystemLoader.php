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

use function count;
use function is_string;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\InvalidMetadataException;

final readonly class FilesystemLoader implements ServableLoaderInterface
{
    /**
     * @param list<string> $paths
     */
    public function __construct(private array $paths)
    {
    }

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $path = ltrim($reference->path ?? '', '/');

        foreach ($this->paths as $basePath) {
            $absolutePath = rtrim($basePath, '/') . '/' . $path;

            if (!is_file($absolutePath)) {
                continue;
            }

            $metadata = [];

            if (count($this->paths) > 1) {
                $metadata['path'] = $basePath;
            }

            $stream = (static fn () => @fopen($absolutePath, 'r') ?: null);

            return new Image(path: $path, stream: $stream, metadata: $metadata);
        }

        return new Image(path: $path);
    }

    /** @param array<string, mixed> $metadata */
    public function getSource(array $metadata): string
    {
        if (isset($metadata['path']) && is_string($metadata['path'])) {
            return $metadata['path'];
        }

        if (1 === count($this->paths)) {
            return $this->paths[0];
        }

        throw new InvalidMetadataException('No path found in metadata and multiple paths configured.');
    }
}
