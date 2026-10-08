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

namespace Silarhi\PicassoBundle\Source;

use function in_array;
use function is_string;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;

use function sprintf;

/**
 * Reads remote images by absolute http(s) URL: the path is the image URL.
 *
 * Only hosts matching the allowed hosts are fetched (any host when none is
 * given): a URL served by a local transformer is fetched by the server, so
 * this list is what keeps a leaked signing key from reaching internal hosts.
 * Disallowed hosts and other schemes are treated as missing, without a request.
 *
 * exists() fetches the image, since only the response tells whether it exists,
 * and keeps the body for the readStream() call that follows: serving a miss
 * costs one request.
 */
final class HttpImageSource implements ImageSourceInterface
{
    private const SCHEMES = ['http', 'https'];

    /**
     * Body of the last image exists() fetched, until readStream() takes it.
     *
     * @var array{url: string, stream: resource}|null
     */
    private ?array $pending = null;

    /**
     * @param list<string> $allowedHosts Hosts images may be fetched from: "example.com", or "*.example.com" for its
     *                                   subdomains. Empty allows any host.
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly array $allowedHosts = [],
    ) {
    }

    public function __destruct()
    {
        $this->discardPending();
    }

    public function exists(string $path): bool
    {
        $url = $this->url($path);
        if (null === $url) {
            return false;
        }

        $this->discardPending();

        try {
            $this->pending = ['url' => $url, 'stream' => $this->fetch($url)];
        } catch (ImageNotFoundException) {
            return false;
        }

        return true;
    }

    public function readStream(string $path)
    {
        $url = $this->url($path) ?? throw new ImageNotFoundException(sprintf('Image "%s" is not an http(s) URL on an allowed host.', $path));

        if (null !== $this->pending && $this->pending['url'] === $url) {
            $stream = $this->pending['stream'];
            $this->pending = null;

            return $stream;
        }

        return $this->fetch($url);
    }

    /**
     * @return resource
     *
     * @throws ImageNotFoundException When the request fails or the response is not successful
     */
    private function fetch(string $url)
    {
        try {
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', $url));
        } catch (ClientExceptionInterface $e) {
            throw new ImageNotFoundException(sprintf('Image "%s" could not be fetched.', $url), $e->getCode(), previous: $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new ImageNotFoundException(sprintf('Image "%s" could not be fetched: HTTP %d.', $url, $status));
        }

        return $response->getBody()->detach() ?? throw new ImageNotFoundException(sprintf('Image "%s" could not be read.', $url));
    }

    /**
     * The URL a path stands for, or null when it may not be fetched.
     */
    private function url(string $path): ?string
    {
        // Paths reach Glide's source through Flysystem, whose normalizer merges
        // the slashes of "https://host/…" into "https:/host/…"
        $url = preg_replace('#^(https?):/(?!/)#i', '$1://', $path) ?? $path;

        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);
        if (!is_string($scheme) || !in_array(strtolower($scheme), self::SCHEMES, true) || !is_string($host) || '' === $host) {
            return null;
        }

        return $this->isAllowedHost(strtolower($host)) ? $url : null;
    }

    /**
     * @param string $host The lowercased host of the requested URL
     */
    private function isAllowedHost(string $host): bool
    {
        if ([] === $this->allowedHosts) {
            return true;
        }

        foreach ($this->allowedHosts as $allowedHost) {
            $allowedHost = strtolower($allowedHost);

            // "*.example.com" allows its subdomains only; the leading dot keeps
            // "evil-example.com" from matching
            if (str_starts_with($allowedHost, '*.')
                ? str_ends_with($host, substr($allowedHost, 1))
                : $host === $allowedHost) {
                return true;
            }
        }

        return false;
    }

    private function discardPending(): void
    {
        if (null !== $this->pending) {
            fclose($this->pending['stream']);
            $this->pending = null;
        }
    }
}
