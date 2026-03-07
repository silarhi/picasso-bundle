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
use Silarhi\PicassoBundle\Exception\PlaceholderNotFoundException;
use Silarhi\PicassoBundle\Placeholder\PlaceholderInterface;

use function sprintf;

final readonly class PlaceholderRegistry
{
    public function __construct(
        private ContainerInterface $placeholders,
    ) {
    }

    public function get(string $name): PlaceholderInterface
    {
        if (!$this->has($name)) {
            throw new PlaceholderNotFoundException(sprintf('Placeholder "%s" not found.', $name));
        }

        $placeholder = $this->placeholders->get($name);
        assert($placeholder instanceof PlaceholderInterface);

        return $placeholder;
    }

    public function has(string $name): bool
    {
        return $this->placeholders->has($name);
    }
}
