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

use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Silarhi\PicassoBundle\Source\StorageFailure;
use Silarhi\PicassoBundle\Tests\Source\Stub\ObjectStoreFailures;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class StorageFailureTest extends TestCase
{
    use ObjectStoreFailures;

    /**
     * @return iterable<string, array{int|null, bool}>
     */
    public static function answerProvider(): iterable
    {
        yield 'no answer (connection refused, timeout)' => [null, true];
        yield 'server error' => [500, true];
        yield 'service unavailable' => [503, true];
        yield 'request timeout' => [408, true];
        yield 'too early' => [425, true];
        yield 'too many requests' => [429, true];
        yield 'not found' => [404, false];
        yield 'access denied' => [403, false];
        yield 'conflict' => [409, false];
    }

    #[DataProvider('answerProvider')]
    public function testReadsTheAnswerOfTheObjectStoreBehindTheFlysystemException(?int $status, bool $transient): void
    {
        $failure = StorageFailure::of(UnableToReadFile::fromLocation('photo.jpg', 'GetObject failed', self::objectStoreException($status)));

        self::assertSame($status, $failure->status);
        self::assertSame($transient, $failure->transient);
    }

    public function testTheFirstAnswerOfTheChainWins(): void
    {
        $chain = UnableToReadFile::fromLocation('photo.jpg', 'GetObject failed', new RuntimeException('SDK error', 0, self::objectStoreException(404)));

        self::assertSame(404, StorageFailure::of($chain)->status);
    }

    public function testAFailureWithoutHttpClientExceptionHasNoStatus(): void
    {
        // Local disk, SFTP...: those failures keep meaning "missing"
        $failure = StorageFailure::of(UnableToReadFile::fromLocation('photo.jpg', 'No such file or directory'));

        self::assertNull($failure->status);
        self::assertFalse($failure->transient);
        self::assertFalse($failure->isConflict());
    }

    public function testAPsr18NetworkErrorIsTransient(): void
    {
        $networkError = new class('Could not resolve host.') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                throw new RuntimeException('Not needed.');
            }
        };

        self::assertTrue(StorageFailure::of(UnableToReadFile::fromLocation('photo.jpg', 'failed', $networkError))->transient);
    }

    public function testASymfonyHttpClientTransportErrorIsTransient(): void
    {
        $failure = StorageFailure::of(UnableToReadFile::fromLocation('photo.jpg', 'failed', new TransportException('Idle timeout reached.')));

        self::assertNull($failure->status);
        self::assertTrue($failure->transient);
    }

    public function testReadsTheAnswerOfASymfonyHttpClientException(): void
    {
        $unavailable = StorageFailure::of(UnableToReadFile::fromLocation('photo.jpg', 'failed', new ServerException($this->symfonyResponse(503))));
        $missing = StorageFailure::of(UnableToReadFile::fromLocation('photo.jpg', 'failed', new ClientException($this->symfonyResponse(404))));

        self::assertSame(503, $unavailable->status);
        self::assertTrue($unavailable->transient);
        self::assertSame(404, $missing->status);
        self::assertFalse($missing->transient);
    }

    /**
     * @return iterable<string, array{int|null, bool}>
     */
    public static function conflictProvider(): iterable
    {
        yield 'conflict' => [409, true];
        yield 'precondition failed' => [412, true];
        yield 'access denied' => [403, false];
        yield 'server error' => [500, false];
        yield 'no answer' => [null, false];
    }

    #[DataProvider('conflictProvider')]
    public function testRecognizesTheRejectionOfAConcurrentWrite(?int $status, bool $conflict): void
    {
        $failure = StorageFailure::of(UnableToWriteFile::atLocation('photo.jpg/w_10.webp', 'PutObject failed', self::objectStoreException($status)));

        self::assertSame($conflict, $failure->isConflict());
    }

    private function symfonyResponse(int $status): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return (new MockHttpClient(new MockResponse('', ['http_code' => $status])))->request('GET', 'https://storage.example.com/photo.jpg');
    }
}
