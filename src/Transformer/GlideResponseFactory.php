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

namespace Silarhi\PicassoBundle\Transformer;

use function assert;

use DateTimeImmutable;

use function is_string;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Glide\Responses\ResponseFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Answers a request with a stored Glide variant, in as few storage calls as possible.
 *
 * Glide's Symfony response factory asks the storage for the stream, the mime
 * type, the size and the modification date of every variant it serves, after
 * Glide itself asked whether the variant exists: five calls, each an HTTP request
 * on an object store. Here a plain request costs two (the size, which also tells
 * whether the variant exists, then the stream), the content type is read from the
 * first bytes of the stream, and the date comes from the stream when it is a
 * local file. A conditional request costs the date first, so a 304 never opens
 * the variant.
 *
 * On a remote storage, responses therefore carry no Last-Modified header: signed
 * URLs never change, and the image controller marks them cacheable for a year.
 *
 * @internal
 */
final readonly class GlideResponseFactory implements ResponseFactoryInterface
{
    /**
     * Lifetime Glide's own response factory gives a variant. The image controller
     * replaces it with the bundle's cache_control config.
     */
    private const MAX_AGE = 31536000;

    /**
     * Bytes read up front to tell the image format; enough for every format Glide encodes.
     */
    private const SNIFF_LENGTH = 16;

    public function __construct(
        private Request $request,
    ) {
    }

    /**
     * Called by Glide once it has rendered a miss into $cache.
     *
     * @param mixed $path The variant's path in $cache (untyped in Glide 2's interface)
     *
     * @throws FilesystemException When the variant cannot be read
     */
    public function create(FilesystemOperator $cache, mixed $path): Response
    {
        assert(is_string($path));

        return $this->respond($cache, $path);
    }

    /**
     * The response for a variant the storage already holds, or null when it does
     * not. A variant the storage cannot tell about is reported missing, as Glide
     * does: it is then rendered again rather than failing the request.
     */
    public function fromCache(FilesystemOperator $cache, string $path): ?Response
    {
        try {
            return $this->respond($cache, $path);
        } catch (FilesystemException) {
            return null;
        }
    }

    /**
     * @throws FilesystemException
     */
    private function respond(FilesystemOperator $cache, string $path): Response
    {
        $size = null;
        $lastModified = null;

        // The first call also tells whether the variant exists
        if ($this->request->headers->has('If-Modified-Since')) {
            $lastModified = $cache->lastModified($path);
            $notModified = $this->withCacheHeaders(new Response(), $lastModified);
            if ($notModified->isNotModified($this->request)) {
                return $notModified;
            }
        } else {
            $size = $cache->fileSize($path);
        }

        $stream = $cache->readStream($path);
        $head = (string) stream_get_contents($stream, self::SNIFF_LENGTH);

        // A local file states its size and date for free
        if ('plainfile' === stream_get_meta_data($stream)['wrapper_type']) {
            $stat = fstat($stream);
            if (false !== $stat) {
                $size ??= $stat['size'];
                $lastModified ??= $stat['mtime'];
            }
        }

        $response = new StreamedResponse(static function () use ($stream, $head): void {
            echo $head;
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', self::sniffMimeType($head) ?? self::storedMimeType($cache, $path));
        $response->headers->set('Content-Length', (string) ($size ?? $cache->fileSize($path)));

        return $this->withCacheHeaders($response, $lastModified);
    }

    private function withCacheHeaders(Response $response, ?int $lastModified): Response
    {
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->setExpires(new DateTimeImmutable('+1 year'));

        if (null !== $lastModified) {
            $response->setLastModified((new DateTimeImmutable())->setTimestamp($lastModified));
        }

        return $response;
    }

    /**
     * The mime type the storage tells for a format Glide does not encode itself.
     * A storage unable to tell (e.g. an extension-less path on local disk) must
     * not fail the request: the variant is there.
     */
    private static function storedMimeType(FilesystemOperator $cache, string $path): string
    {
        try {
            return $cache->mimeType($path);
        } catch (FilesystemException) {
            return 'application/octet-stream';
        }
    }

    /**
     * The mime type of the formats Glide encodes, from their magic bytes. Null for
     * anything else, which is then asked to the storage.
     */
    private static function sniffMimeType(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, 'RIFF') && 'WEBP' === substr($head, 8, 4) => 'image/webp',
            'ftyp' === substr($head, 4, 4) => match (substr($head, 8, 4)) {
                'avif', 'avis' => 'image/avif',
                'heic', 'heix', 'hevc', 'hevx' => 'image/heic',
                default => null,
            },
            str_starts_with($head, 'BM') => 'image/bmp',
            str_starts_with($head, "II*\x00"), str_starts_with($head, "MM\x00*") => 'image/tiff',
            default => null,
        };
    }
}
