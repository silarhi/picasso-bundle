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

use function in_array;

use Silarhi\PicassoBundle\Exception\ImageNotFoundException;

use function sprintf;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;

/**
 * Reads the images AssetMapper maps, by logical path ("images/hero.jpg", as given to asset()).
 *
 * AssetMapper resolves a logical path in every asset directory, without refusing "..": paths with a ".." segment or
 * a null byte are treated as missing, so only mapped assets can be read.
 */
final readonly class AssetMapperImageSource implements ImageSourceInterface
{
    public function __construct(
        private AssetMapperInterface $assetMapper,
    ) {
    }

    public function exists(string $path): bool
    {
        return null !== $this->asset($path);
    }

    public function readStream(string $path)
    {
        $asset = $this->asset($path);

        if (null === $asset) {
            throw new ImageNotFoundException(sprintf('Image "%s" not found.', $path));
        }

        $handle = @fopen($asset->sourcePath, 'r');
        if (false === $handle) {
            throw new ImageNotFoundException(sprintf('Image "%s" could not be opened.', $path));
        }

        return $handle;
    }

    private function asset(string $path): ?MappedAsset
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ('' === $path || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $this->assetMapper->getAsset($path);
    }
}
