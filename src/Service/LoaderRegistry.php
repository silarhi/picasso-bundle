<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Service;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;

final readonly class LoaderRegistry
{
    public function __construct(
        private ContainerInterface $loaders,
    ) {
    }

    public function get(string $name): ImageLoaderInterface
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Loader "%s" not found.', $name));
        }

        $loader = $this->loaders->get($name);
        \assert($loader instanceof ImageLoaderInterface);

        return $loader;
    }

    public function has(string $name): bool
    {
        return $this->loaders->has($name);
    }
}
