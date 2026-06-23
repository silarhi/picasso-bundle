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

use function strlen;

/**
 * @phpstan-import-type ImageGuessedMetadata from MetadataGuesserInterface
 */
final readonly class MetadataGuesser implements MetadataGuesserInterface
{
    /**
     * Cache namespace, versioned to invalidate entries poisoned by the former
     * fixed 64KB read cap (failed guesses were cached with null dimensions).
     */
    private const CACHE_NAMESPACE = 'metadata.v2';

    /** Initial read size; covers images whose header sits near the start (the vast majority). */
    private const INITIAL_READ_SIZE = 65536;

    /** Stop growing the buffer past this point (unparseable formats, e.g. SVG). */
    private const MAX_READ_SIZE = 2097152;

    public function __construct(
        private ?CacheItemPoolInterface $cache = null,
    ) {
    }

    /**
     * Guess image dimensions and MIME type from a stream.
     * Reads progressively, starting with the first bytes needed for header
     * detection and growing the buffer when the dimensions sit further into
     * the file (e.g. JPEGs with large embedded EXIF/ICC/XMP segments).
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
            $cacheKey = CacheKeyGenerator::generate(self::CACHE_NAMESPACE, [$identifier]);
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
        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
        }

        $data = '';

        do {
            // Double the buffer on each pass: 64KB, 128KB, 256KB, ... up to MAX_READ_SIZE
            $chunkSize = '' === $data ? self::INITIAL_READ_SIZE : strlen($data);
            $chunk = stream_get_contents($stream, $chunkSize);

            if (false === $chunk || '' === $chunk) {
                break;
            }

            $data .= $chunk;
            $info = @getimagesizefromstring($data);

            if (false !== $info) {
                return [
                    'width' => $info[0],
                    'height' => $info[1],
                    'mimeType' => $info['mime'],
                ];
            }

            if (strlen($chunk) < $chunkSize) {
                break; // EOF: getimagesizefromstring already saw the whole stream
            }
        } while (strlen($data) < self::MAX_READ_SIZE);

        return ['width' => null, 'height' => null, 'mimeType' => null];
    }
}
