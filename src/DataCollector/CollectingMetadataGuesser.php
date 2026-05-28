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

namespace Silarhi\PicassoBundle\DataCollector;

use Closure;
use Override;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;

/**
 * Decorates MetadataGuesserInterface to record metadata-guess operations into the data collector.
 *
 * Only registered when the bundle's `collector` option is enabled.
 *
 * @phpstan-import-type ImageGuessedMetadata from MetadataGuesserInterface
 */
final readonly class CollectingMetadataGuesser implements MetadataGuesserInterface
{
    public function __construct(
        private MetadataGuesserInterface $inner,
        private PicassoDataCollector $collector,
    ) {
    }

    /**
     * @param resource|(Closure(): (resource|null)) $stream
     *
     * @return ImageGuessedMetadata
     */
    #[Override]
    public function guess(mixed $stream, ?string $identifier = null): array
    {
        $start = microtime(true);
        $result = $this->inner->guess($stream, $identifier);
        $duration = (microtime(true) - $start) * 1000;

        $this->collector->collectMetadataGuess(
            key: $identifier ?? '(anonymous)',
            width: $result['width'],
            height: $result['height'],
            mimeType: $result['mimeType'],
            duration: $duration,
        );

        return $result;
    }
}
