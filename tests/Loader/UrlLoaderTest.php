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

namespace Silarhi\PicassoBundle\Tests\Loader;

use Closure;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\UrlLoader;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UrlLoaderTest extends TestCase
{
    public function testLoadWithEmptyPathReturnsEmptyImage(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $loader = new UrlLoader($httpClient);
        $image = $loader->load(new ImageReference(''));

        self::assertNull($image->path);
        self::assertNull($image->stream);
    }

    public function testLoadWithNullPathReturnsEmptyImage(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $loader = new UrlLoader($httpClient);
        $image = $loader->load(new ImageReference());

        self::assertNull($image->path);
        self::assertNull($image->stream);
    }

    public function testLoadWithUrlReturnsImageWithUrlAndLazyStream(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        // The HTTP client should NOT be called during load() — only when the stream closure is invoked
        $httpClient->expects(self::never())->method('request');

        $loader = new UrlLoader($httpClient);
        $image = $loader->load(new ImageReference('https://example.com/photo.jpg'));

        self::assertSame('https://example.com/photo.jpg', $image->url);
        self::assertInstanceOf(Closure::class, $image->stream);
    }

    public function testLoadWithMetadataFlagDoesNotChangeResult(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $loader = new UrlLoader($httpClient);
        $image = $loader->load(new ImageReference('https://example.com/photo.jpg'), withMetadata: true);

        self::assertSame('https://example.com/photo.jpg', $image->url);
        self::assertInstanceOf(Closure::class, $image->stream);
        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->mimeType);
    }
}
