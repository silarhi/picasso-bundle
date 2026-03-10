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

use function assert;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;

use function sprintf;

final readonly class LoaderRegistry
{
    /**
     * @param array<string, string> $defaultPlaceholders Loader name → default placeholder name
     */
    public function __construct(
        private ContainerInterface $loaders,
        private array $defaultPlaceholders = [],
    ) {
    }

    public function get(string $name): ImageLoaderInterface
    {
        if (!$this->has($name)) {
            throw new LoaderNotFoundException(sprintf('Loader "%s" not found.', $name));
        }

        $loader = $this->loaders->get($name);
        assert($loader instanceof ImageLoaderInterface);

        return $loader;
    }

    public function has(string $name): bool
    {
        return $this->loaders->has($name);
    }

    public function getDefaultPlaceholder(string $loaderName): ?string
    {
        return $this->defaultPlaceholders[$loaderName] ?? null;
    }
}
