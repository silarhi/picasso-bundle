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

namespace Silarhi\PicassoBundle\Tests\Transformer;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Exception\PurgeException;
use Silarhi\PicassoBundle\Transformer\ImgixTransformer;

class ImgixTransformerPurgeTest extends TestCase
{
    public function testPurgeSendsCorrectRequest(): void
    {
        $sentRequest = null;

        $stream = $this->createMock(StreamInterface::class);

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnCallback(function (string $name, string $value) use ($request, &$headers): RequestInterface {
            $headers[$name] = $value;

            return $request;
        });
        $request->method('withBody')->willReturnSelf();

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $r) use (&$sentRequest, $response): ResponseInterface {
                $sentRequest = $r;

                return $response;
            });

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')
            ->with('POST', 'https://api.imgix.com/api/v1/purge')
            ->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')
            ->willReturnCallback(function (string $content) use ($stream): StreamInterface {
                /** @var array{data: array{type: string, attributes: array{url: string}}} $decoded */
                $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame('purges', $decoded['data']['type']);
                self::assertSame('https://my-source.imgix.net/photos/photo.jpg', $decoded['data']['attributes']['url']);

                return $stream;
            });

        $transformer = new ImgixTransformer(
            'https://my-source.imgix.net',
            null,
            'my-api-key',
            $httpClient,
            $requestFactory,
            $streamFactory,
        );

        $transformer->purge('photos/photo.jpg');

        self::assertNotNull($sentRequest);
    }

    public function testPurgeThrowsWhenApiKeyNotConfigured(): void
    {
        $transformer = new ImgixTransformer('https://my-source.imgix.net');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('api_key');

        $transformer->purge('photo.jpg');
    }

    public function testPurgeThrowsWhenHttpClientNotConfigured(): void
    {
        $transformer = new ImgixTransformer(
            'https://my-source.imgix.net',
            null,
            'my-api-key',
        );

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('http_client');

        $transformer->purge('photo.jpg');
    }

    public function testPurgeThrowsOnNon2xxResponse(): void
    {
        $stream = $this->createMock(StreamInterface::class);

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(403);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $transformer = new ImgixTransformer(
            'https://my-source.imgix.net',
            null,
            'my-api-key',
            $httpClient,
            $requestFactory,
            $streamFactory,
        );

        $this->expectException(PurgeException::class);
        $this->expectExceptionMessage('HTTP 403');

        $transformer->purge('photo.jpg');
    }

    public function testPurgeThrowsOnNetworkError(): void
    {
        $stream = $this->createMock(StreamInterface::class);

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException(new RuntimeException('Connection refused'));

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $transformer = new ImgixTransformer(
            'https://my-source.imgix.net',
            null,
            'my-api-key',
            $httpClient,
            $requestFactory,
            $streamFactory,
        );

        $this->expectException(PurgeException::class);
        $this->expectExceptionMessage('purge request failed');

        $transformer->purge('photo.jpg');
    }

    public function testPurgeStripsLeadingSlashFromPath(): void
    {
        $stream = $this->createMock(StreamInterface::class);

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')
            ->willReturnCallback(function (string $content) use ($stream): StreamInterface {
                /** @var array{data: array{attributes: array{url: string}}} $decoded */
                $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame('https://cdn.imgix.net/photo.jpg', $decoded['data']['attributes']['url']);

                return $stream;
            });

        $transformer = new ImgixTransformer(
            'https://cdn.imgix.net/',
            null,
            'my-api-key',
            $httpClient,
            $requestFactory,
            $streamFactory,
        );

        $transformer->purge('/photo.jpg');
    }
}
