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

namespace Silarhi\PicassoBundle\Tests\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Source\HttpImageSource;

class HttpImageSourceTest extends TestCase
{
    /** @var list<string> */
    private array $requested = [];

    public function testExistsFetchesTheImageAndReadStreamReusesTheResponse(): void
    {
        $source = $this->createSource(['https://example.com/photo.jpg' => [200, 'image-data']]);

        self::assertTrue($source->exists('https://example.com/photo.jpg'));
        $stream = $source->readStream('https://example.com/photo.jpg');

        self::assertSame('image-data', stream_get_contents($stream));
        self::assertSame(['https://example.com/photo.jpg'], $this->requested);
    }

    public function testReadStreamFetchesAgainOnceTheResponseWasTaken(): void
    {
        $source = $this->createSource(['https://example.com/photo.jpg' => [200, 'image-data']]);

        $source->exists('https://example.com/photo.jpg');
        $source->readStream('https://example.com/photo.jpg');
        $source->readStream('https://example.com/photo.jpg');

        self::assertCount(2, $this->requested);
    }

    public function testReadStreamFetchesAnotherImageThanTheOneCheckedLast(): void
    {
        $source = $this->createSource([
            'https://example.com/a.jpg' => [200, 'a'],
            'https://example.com/b.jpg' => [200, 'b'],
        ]);

        $source->exists('https://example.com/a.jpg');

        self::assertSame('b', stream_get_contents($source->readStream('https://example.com/b.jpg')));
    }

    public function testFlysystemNormalizedUrlsAreRepaired(): void
    {
        // Flysystem merges the slashes of "https://" before the path reaches the source
        $source = $this->createSource(['https://example.com/photo.jpg' => [200, 'image-data']]);

        self::assertTrue($source->exists('https:/example.com/photo.jpg'));
        self::assertSame(['https://example.com/photo.jpg'], $this->requested);
    }

    public function testExistsIsFalseForAnUnsuccessfulResponse(): void
    {
        $source = $this->createSource(['https://example.com/missing.jpg' => [404, 'Not Found']]);

        self::assertFalse($source->exists('https://example.com/missing.jpg'));
    }

    public function testExistsIsFalseWhenTheRequestFails(): void
    {
        $source = $this->createSource([]);

        self::assertFalse($source->exists('https://unreachable.example.com/photo.jpg'));
    }

    public function testReadStreamThrowsForAnUnsuccessfulResponse(): void
    {
        $source = $this->createSource(['https://example.com/missing.jpg' => [404, 'Not Found']]);

        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('Image "https://example.com/missing.jpg" could not be fetched: HTTP 404.');

        $source->readStream('https://example.com/missing.jpg');
    }

    public function testReadStreamThrowsWhenTheRequestFails(): void
    {
        $source = $this->createSource([]);

        try {
            $source->readStream('https://unreachable.example.com/photo.jpg');
            self::fail('An unreachable image must not be read.');
        } catch (ImageNotFoundException $e) {
            self::assertInstanceOf(ClientExceptionInterface::class, $e->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unfetchableProvider(): iterable
    {
        yield 'relative path' => ['photo.jpg'];
        yield 'local file' => ['file:///etc/passwd'];
        yield 'other scheme' => ['ftp://example.com/photo.jpg'];
        yield 'no host' => ['https:///photo.jpg'];
        yield 'empty' => [''];
    }

    #[DataProvider('unfetchableProvider')]
    public function testOnlyHttpUrlsAreFetched(string $path): void
    {
        $source = $this->createSource([]);

        self::assertFalse($source->exists($path));
        self::assertSame([], $this->requested);

        $this->expectException(ImageNotFoundException::class);
        $source->readStream($path);
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function allowedHostProvider(): iterable
    {
        yield 'any host when none is listed' => [[], 'https://anything.example.org/a.jpg', true];
        yield 'listed host' => [['images.example.com'], 'https://images.example.com/a.jpg', true];
        yield 'listed host, other case' => [['images.example.com'], 'https://IMAGES.Example.com/a.jpg', true];
        yield 'listed host on another port' => [['images.example.com'], 'https://images.example.com:8443/a.jpg', true];
        yield 'one of several hosts' => [['a.example.com', 'b.example.com'], 'http://b.example.com/a.jpg', true];
        yield 'unlisted host' => [['images.example.com'], 'https://example.com/a.jpg', false];
        yield 'subdomain of a listed host' => [['example.com'], 'https://images.example.com/a.jpg', false];
        yield 'host ending like a listed host' => [['example.com'], 'https://evil-example.com/a.jpg', false];
        yield 'listed host as a subdomain elsewhere' => [['example.com'], 'https://example.com.evil.net/a.jpg', false];
        yield 'listed host as credentials' => [['example.com'], 'https://example.com@internal.local/a.jpg', false];
        yield 'wildcard subdomain' => [['*.example.com'], 'https://images.example.com/a.jpg', true];
        yield 'wildcard nested subdomain' => [['*.example.com'], 'https://eu.cdn.example.com/a.jpg', true];
        yield 'wildcard leaves out the domain itself' => [['*.example.com'], 'https://example.com/a.jpg', false];
        yield 'wildcard and host ending like it' => [['*.example.com'], 'https://images.evil-example.com/a.jpg', false];
    }

    /**
     * @param list<string> $allowedHosts
     */
    #[DataProvider('allowedHostProvider')]
    public function testOnlyAllowedHostsAreFetched(array $allowedHosts, string $url, bool $allowed): void
    {
        $source = $this->createSource([], $allowedHosts, respondToAll: true);

        self::assertSame($allowed, $source->exists($url));
        self::assertCount($allowed ? 1 : 0, $this->requested);
    }

    /**
     * @param array<string, array{int, string}> $responses    Status and body by URL; other URLs fail as unreachable
     * @param list<string>                      $allowedHosts
     */
    private function createSource(array $responses, array $allowedHosts = [], bool $respondToAll = false): HttpImageSource
    {
        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturnCallback(function (string $method, string $url): RequestInterface {
            $request = self::createStub(RequestInterface::class);
            $request->method('getMethod')->willReturn($method);
            $request->method('getUri')->willReturn($this->createUri($url));

            return $request;
        });

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($responses, $respondToAll): ResponseInterface {
            $url = (string) $request->getUri();
            $this->requested[] = $url;

            if (!isset($responses[$url]) && !$respondToAll) {
                throw new class('Could not resolve host.') extends RuntimeException implements ClientExceptionInterface {};
            }

            [$status, $contents] = $responses[$url] ?? [200, 'image-data'];

            return $this->createResponse($status, $contents);
        });

        return new HttpImageSource($httpClient, $requestFactory, $allowedHosts);
    }

    private function createUri(string $url): UriInterface
    {
        $uri = self::createStub(UriInterface::class);
        $uri->method('__toString')->willReturn($url);

        return $uri;
    }

    private function createResponse(int $status, string $contents): ResponseInterface
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        fwrite($resource, $contents);
        rewind($resource);

        $body = self::createStub(StreamInterface::class);
        $body->method('detach')->willReturn($resource);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);

        return $response;
    }
}
