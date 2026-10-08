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

use function assert;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;
use Silarhi\PicassoBundle\Exception\InvalidImageReferenceException;

use function sprintf;

/**
 * Renders each image with the first of its loaders holding it, under one loader name.
 *
 * A loader reads a single root, so images spread over several roots (an uploads
 * directory and an assets one, several VichUploader mappings...) need several
 * loaders. A chain keeps one name for templates:
 *
 * - a reference with a path goes to the first loader whose source has it, or
 *   to the first loader when none does (the URL then 404s, as with any loader);
 *   a source that cannot tell (unavailable storage) counts as not having it,
 *   so rendering goes on while the storage is down;
 * - a reference without a path (e.g. an entity) goes to the first loader that
 *   accepts it: vich loaders reject entities with no field using their mapping.
 *
 * The returned image names the loader that loaded it, so generated URLs name
 * that loader: a chain itself never serves.
 */
final readonly class ChainLoader implements ImageLoaderInterface
{
    /**
     * @param ContainerInterface $loaders The chained loaders, by name
     * @param list<string>       $chain   Their names, in the order they are tried
     */
    public function __construct(
        private string $name,
        private ContainerInterface $loaders,
        private array $chain,
    ) {
    }

    /**
     * @throws InvalidImageReferenceException When no chained loader accepts the reference
     */
    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $fallback = null;

        foreach ($this->chain as $name) {
            $loader = $this->loaders->get($name);
            assert($loader instanceof ServableLoaderInterface);

            try {
                $image = $loader->load($reference, $withMetadata);
            } catch (InvalidImageReferenceException) {
                continue;
            }

            $image = new Image($image->path, $image->stream, $image->width, $image->height, $image->mimeType, $name);

            if (null === $reference->path || $this->holds($loader, $image->path ?? '')) {
                return $image;
            }

            $fallback ??= $image;
        }

        return $fallback ?? throw new InvalidImageReferenceException(sprintf('No loader of chain "%s" (%s) accepts this image reference.', $this->name, implode(', ', $this->chain)));
    }

    private function holds(ServableLoaderInterface $loader, string $path): bool
    {
        try {
            return $loader->getSource()->exists($path);
        } catch (ImageSourceUnavailableException) {
            return false;
        }
    }
}
