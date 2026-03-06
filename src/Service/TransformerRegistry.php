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
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;

use function sprintf;

final readonly class TransformerRegistry
{
    public function __construct(
        private ContainerInterface $transformers,
    ) {
    }

    public function get(string $name): ImageTransformerInterface
    {
        if (!$this->has($name)) {
            throw new TransformerNotFoundException(sprintf('Transformer "%s" not found.', $name));
        }

        $transformer = $this->transformers->get($name);
        assert($transformer instanceof ImageTransformerInterface);

        return $transformer;
    }

    public function has(string $name): bool
    {
        return $this->transformers->has($name);
    }
}
