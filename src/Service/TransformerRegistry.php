<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Service;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

final readonly class TransformerRegistry
{
    public function __construct(
        private ContainerInterface $transformers,
    ) {
    }

    public function get(string $name): ImageTransformerInterface
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Transformer "%s" not found.', $name));
        }

        $transformer = $this->transformers->get($name);
        \assert($transformer instanceof ImageTransformerInterface);

        return $transformer;
    }

    public function has(string $name): bool
    {
        return $this->transformers->has($name);
    }
}
