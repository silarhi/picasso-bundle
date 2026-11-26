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

use Silarhi\PicassoBundle\Dto\ImagePreset;
use Silarhi\PicassoBundle\Exception\PresetNotFoundException;

use function sprintf;

/**
 * The presets of the bundle configuration, by name.
 */
final readonly class PresetRegistry
{
    /**
     * @param array<string, ImagePreset> $presets
     */
    public function __construct(
        private array $presets = [],
    ) {
    }

    /**
     * @throws PresetNotFoundException When no preset has that name
     */
    public function get(string $name): ImagePreset
    {
        return $this->presets[$name] ?? throw new PresetNotFoundException(sprintf('Preset "%s" not found. Available: %s.', $name, [] !== $this->presets ? '"' . implode('", "', array_keys($this->presets)) . '"' : 'none (configure them under "picasso.presets")'));
    }

    public function has(string $name): bool
    {
        return isset($this->presets[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->presets);
    }
}
