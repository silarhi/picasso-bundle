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

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;

use function sprintf;

final readonly class LoaderRegistry
{
    public function __construct(
        private ContainerInterface $loaders,
    ) {
    }

    public function get(string $name): ImageLoaderInterface
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException(sprintf('Loader "%s" not found.', $name));
        }

        $loader = $this->loaders->get($name);
        assert($loader instanceof ImageLoaderInterface);

        return $loader;
    }

    public function has(string $name): bool
    {
        return $this->loaders->has($name);
    }
}
