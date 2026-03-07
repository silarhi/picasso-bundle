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
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Loader\UrlLoader;

class UrlLoaderTest extends TestCase
{
    public function testLoadWithEmptyPathReturnsEmptyImage(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::never())->method('sendRequest');

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::never())->method('createRequest');

        $loader = new UrlLoader($httpClient, $requestFactory);
        $image = $loader->load(new ImageReference(''));

        self::assertNull($image->path);
        self::assertNull($image->stream);
    }

    public function testLoadWithNullPathReturnsEmptyImage(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::never())->method('sendRequest');

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::never())->method('createRequest');

        $loader = new UrlLoader($httpClient, $requestFactory);
        $image = $loader->load(new ImageReference());

        self::assertNull($image->path);
        self::assertNull($image->stream);
    }

    public function testLoadWithUrlReturnsImageWithUrlAndLazyStream(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);

        // The HTTP client should NOT be called during load() — only when the stream closure is invoked
        $httpClient->expects(self::never())->method('sendRequest');

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::never())->method('createRequest');

        $loader = new UrlLoader($httpClient, $requestFactory);
        $image = $loader->load(new ImageReference('https://example.com/photo.jpg'));

        self::assertSame('https://example.com/photo.jpg', $image->url);
        self::assertInstanceOf(Closure::class, $image->stream);
    }

    public function testLoadWithMetadataFlagDoesNotChangeResult(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);

        $loader = new UrlLoader($httpClient, $requestFactory);
        $image = $loader->load(new ImageReference('https://example.com/photo.jpg'), withMetadata: true);

        self::assertSame('https://example.com/photo.jpg', $image->url);
        self::assertInstanceOf(Closure::class, $image->stream);
        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->mimeType);
    }

    public function testStreamClosureCallsHttpClientAndReturnsResource(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'image-data');
        rewind($stream);

        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('detach')->willReturn($stream);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($bodyStream);

        $request = $this->createMock(RequestInterface::class);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('GET', 'https://example.com/photo.jpg')
            ->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->with($request)
            ->willReturn($response);

        $loader = new UrlLoader($httpClient, $requestFactory);
        $image = $loader->load(new ImageReference('https://example.com/photo.jpg'));

        $result = $image->resolveStream();
        self::assertIsResource($result);
        self::assertSame('image-data', stream_get_contents($result));
    }
}
