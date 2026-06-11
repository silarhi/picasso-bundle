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

namespace Silarhi\PicassoBundle\DataCollector\Dto;

final readonly class Totals
{
    /**
     * Count shown in the toolbar badge: renders + urls (direct Twig calls).
     */
    public int $headline;

    /**
     * Total recorded operations of any type. When zero, the toolbar item is
     * hidden, the menu entry is disabled and the panel shows an empty state.
     */
    public int $handled;

    public function __construct(
        public int $renders,
        public int $urls,
        public int $metadata,
        public float $duration,
    ) {
        $this->headline = $renders + $urls;
        $this->handled = $renders + $urls + $metadata;
    }
}
