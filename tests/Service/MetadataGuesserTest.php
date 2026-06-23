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

use function dirname;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Service\CacheKeyGenerator;
use Silarhi\PicassoBundle\Service\MetadataGuesser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class MetadataGuesserTest extends TestCase
{
    private MetadataGuesser $guesser;

    protected function setUp(): void
    {
        $this->guesser = new MetadataGuesser();
    }

    public function testGuessGifImage(): void
    {
        $gif = file_get_contents(dirname(__DIR__) . '/Fixtures/pixel.gif');
        self::assertNotFalse($gif);
        $stream = $this->createStream($gif);

        $result = $this->guesser->guess($stream);

        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertSame('image/gif', $result['mimeType']);
    }

    public function testGuessPngImage(): void
    {
        $png = file_get_contents(dirname(__DIR__) . '/Fixtures/2x3.png');
        self::assertNotFalse($png);
        $stream = $this->createStream($png);

        $result = $this->guesser->guess($stream);

        self::assertSame(2, $result['width']);
        self::assertSame(3, $result['height']);
        self::assertSame('image/png', $result['mimeType']);
    }

    public function testGuessJpegImage(): void
    {
        $jpeg = file_get_contents(dirname(__DIR__) . '/Fixtures/photo.jpg');
        self::assertNotFalse($jpeg);
        $stream = $this->createStream($jpeg);

        $result = $this->guesser->guess($stream);

        self::assertSame(100, $result['width']);
        self::assertSame(50, $result['height']);
        self::assertSame('image/jpeg', $result['mimeType']);
    }

    public function testGuessJpegWithHeadersBeyondInitialReadSize(): void
    {
        $jpeg = file_get_contents(dirname(__DIR__) . '/Fixtures/photo.jpg');
        self::assertNotFalse($jpeg);

        // Insert two ~48KB APP1 segments after SOI so the SOF marker (which
        // holds the dimensions) sits beyond the initial 64KB read, mimicking
        // JPEGs with large embedded EXIF/ICC/XMP blocks.
        $payload = str_repeat("\0", 49150);
        $segment = "\xFF\xE1" . pack('n', 49152) . $payload;
        $jpegWithMetadata = substr($jpeg, 0, 2) . $segment . $segment . substr($jpeg, 2);

        $stream = $this->createStream($jpegWithMetadata);

        $result = $this->guesser->guess($stream);

        self::assertSame(100, $result['width']);
        self::assertSame(50, $result['height']);
        self::assertSame('image/jpeg', $result['mimeType']);
    }

    public function testGuessGivesUpOnUnparseableLargeStream(): void
    {
        $stream = $this->createStream(str_repeat("\0", 3 * 1024 * 1024));

        $result = $this->guesser->guess($stream);

        self::assertNull($result['width']);
        self::assertNull($result['height']);
        self::assertNull($result['mimeType']);
    }

    public function testGuessIgnoresCacheEntriesFromFormerNamespace(): void
    {
        $cache = new ArrayAdapter();

        // Simulate an entry poisoned by the former 64KB read cap, stored
        // under the pre-v2 cache namespace
        $item = $cache->getItem(CacheKeyGenerator::generate('metadata', ['poisoned.gif']));
        $item->set(['width' => null, 'height' => null, 'mimeType' => null]);
        $cache->save($item);

        $guesser = new MetadataGuesser($cache);
        $gif = file_get_contents(dirname(__DIR__) . '/Fixtures/pixel.gif');
        self::assertNotFalse($gif);

        $result = $guesser->guess($this->createStream($gif), 'poisoned.gif');

        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertSame('image/gif', $result['mimeType']);
    }

    public function testGuessEmptyStreamReturnsNulls(): void
    {
        $stream = $this->createStream('');

        $result = $this->guesser->guess($stream);

        self::assertNull($result['width']);
        self::assertNull($result['height']);
        self::assertNull($result['mimeType']);
    }

    public function testGuessInvalidDataReturnsNulls(): void
    {
        $stream = $this->createStream('this is not an image');

        $result = $this->guesser->guess($stream);

        self::assertNull($result['width']);
        self::assertNull($result['height']);
        self::assertNull($result['mimeType']);
    }

    public function testGuessReadsFromOffset(): void
    {
        $gif = file_get_contents(dirname(__DIR__) . '/Fixtures/pixel.gif');
        self::assertNotFalse($gif);
        $stream = $this->createStream($gif);

        // Advance the stream position
        fread($stream, 5);

        // guess() should still work (reads from offset 0)
        $result = $this->guesser->guess($stream);

        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
    }

    public function testGuessWithCacheStoresAndReturnsResult(): void
    {
        $cache = new ArrayAdapter();
        $guesser = new MetadataGuesser($cache);

        $gif = file_get_contents(dirname(__DIR__) . '/Fixtures/pixel.gif');
        self::assertNotFalse($gif);
        $stream = $this->createStream($gif);

        // First call: populates cache
        $result = $guesser->guess($stream, 'test-image.gif');
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertSame('image/gif', $result['mimeType']);

        // Second call with a broken stream: should return cached result
        $emptyStream = $this->createStream('');
        $cached = $guesser->guess($emptyStream, 'test-image.gif');
        self::assertSame(1, $cached['width']);
        self::assertSame(1, $cached['height']);
        self::assertSame('image/gif', $cached['mimeType']);
    }

    public function testGuessWithCacheButNoIdentifierSkipsCache(): void
    {
        $cache = new ArrayAdapter();
        $guesser = new MetadataGuesser($cache);

        $gif = file_get_contents(dirname(__DIR__) . '/Fixtures/pixel.gif');
        self::assertNotFalse($gif);
        $stream = $this->createStream($gif);

        $result = $guesser->guess($stream);
        self::assertSame(1, $result['width']);

        // Without identifier, each call reads the stream
        $emptyStream = $this->createStream('');
        $result2 = $guesser->guess($emptyStream);
        self::assertNull($result2['width']);
    }

    public function testGuessWithClosureResolvesLazily(): void
    {
        $gif = file_get_contents(dirname(__DIR__) . '/Fixtures/pixel.gif');
        self::assertNotFalse($gif);

        $invoked = false;
        $closure = function () use ($gif, &$invoked) {
            $invoked = true;

            return $this->createStream($gif);
        };

        $result = $this->guesser->guess($closure);

        self::assertTrue($invoked);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertSame('image/gif', $result['mimeType']);
    }

    public function testGuessWithClosureNotInvokedOnCacheHit(): void
    {
        $cache = new ArrayAdapter();
        $guesser = new MetadataGuesser($cache);

        $gif = file_get_contents(dirname(__DIR__) . '/Fixtures/pixel.gif');
        self::assertNotFalse($gif);

        // First call: populates cache with a real stream
        $guesser->guess($this->createStream($gif), 'lazy-test.gif');

        // Second call: closure should NOT be invoked
        $invoked = false;
        $closure = function () use (&$invoked) {
            $invoked = true;

            return $this->createStream('');
        };

        $cached = $guesser->guess($closure, 'lazy-test.gif');

        self::assertFalse($invoked, 'Closure should not be invoked on cache hit');
        self::assertSame(1, $cached['width']);
        self::assertSame(1, $cached['height']);
        self::assertSame('image/gif', $cached['mimeType']);
    }

    public function testGuessWithClosureReturningNullReturnsNulls(): void
    {
        $result = $this->guesser->guess(static fn () => null);

        self::assertNull($result['width']);
        self::assertNull($result['height']);
        self::assertNull($result['mimeType']);
    }

    /**
     * @return resource
     */
    private function createStream(string $data)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, $data);
        rewind($stream);

        return $stream;
    }
}
