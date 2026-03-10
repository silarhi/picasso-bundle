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
