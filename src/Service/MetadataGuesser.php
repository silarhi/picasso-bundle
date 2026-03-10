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

use Closure;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @phpstan-import-type ImageGuessedMetadata from MetadataGuesserInterface
 */
final readonly class MetadataGuesser implements MetadataGuesserInterface
{
    private const READ_SIZE = 65536;

    public function __construct(
        private ?CacheItemPoolInterface $cache = null,
    ) {
    }

    /**
     * Guess image dimensions and MIME type from a stream.
     * Reads only the first bytes needed for header detection.
     *
     * When the stream is a Closure, it is only invoked on cache miss,
     * avoiding unnecessary I/O for cached metadata.
     *
     * @param resource|(Closure(): (resource|null)) $stream
     *
     * @return ImageGuessedMetadata
     */
    public function guess(mixed $stream, ?string $identifier = null): array
    {
        if ($this->cache instanceof CacheItemPoolInterface && null !== $identifier) {
            $cacheKey = CacheKeyGenerator::generate('metadata', [$identifier]);
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
                /** @var array{width: int|null, height: int|null, mimeType: string|null} $cached */
                $cached = $item->get();

                return $cached;
            }

            $resolved = $stream instanceof Closure ? $stream() : $stream;
            if (null === $resolved) {
                return ['width' => null, 'height' => null, 'mimeType' => null];
            }

            $result = $this->doGuess($resolved);
            $item->set($result);
            $this->cache->save($item);

            return $result;
        }

        $resolved = $stream instanceof Closure ? $stream() : $stream;
        if (null === $resolved) {
            return ['width' => null, 'height' => null, 'mimeType' => null];
        }

        return $this->doGuess($resolved);
    }

    /**
     * @param resource $stream
     *
     * @return array{width: int|null, height: int|null, mimeType: string|null}
     */
    private function doGuess($stream): array
    {
        $data = stream_get_contents($stream, self::READ_SIZE, 0);

        if (false === $data || '' === $data) {
            return ['width' => null, 'height' => null, 'mimeType' => null];
        }

        $info = @getimagesizefromstring($data);

        if (false === $info) {
            return ['width' => null, 'height' => null, 'mimeType' => null];
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
            'mimeType' => $info['mime'],
        ];
    }
}
