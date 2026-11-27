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

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImagePreset;
use Silarhi\PicassoBundle\Exception\PresetNotFoundException;
use Silarhi\PicassoBundle\Service\PresetRegistry;

class PresetRegistryTest extends TestCase
{
    public function testGetReturnsThePreset(): void
    {
        $card = new ImagePreset(width: 300);
        $registry = new PresetRegistry(['card' => $card, 'hero' => new ImagePreset(width: 1200)]);

        self::assertSame($card, $registry->get('card'));
    }

    public function testGetThrowsForAnUnknownPreset(): void
    {
        $registry = new PresetRegistry(['card' => new ImagePreset(), 'hero' => new ImagePreset()]);

        $this->expectException(PresetNotFoundException::class);
        $this->expectExceptionMessage('Preset "nope" not found. Available: "card", "hero".');

        $registry->get('nope');
    }

    public function testGetWithoutPresetsSaysWhereToConfigureThem(): void
    {
        $this->expectException(PresetNotFoundException::class);
        $this->expectExceptionMessage('Preset "card" not found. Available: none (configure them under "picasso.presets").');

        (new PresetRegistry())->get('card');
    }

    public function testHas(): void
    {
        $registry = new PresetRegistry(['card' => new ImagePreset()]);

        self::assertTrue($registry->has('card'));
        self::assertFalse($registry->has('hero'));
    }

    public function testNames(): void
    {
        self::assertSame(['card', 'hero'], (new PresetRegistry(['card' => new ImagePreset(), 'hero' => new ImagePreset()]))->names());
        self::assertSame([], (new PresetRegistry())->names());
    }
}
