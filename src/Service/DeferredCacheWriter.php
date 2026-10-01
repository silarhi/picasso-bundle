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

use function is_resource;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Moves rendered variants from a local render directory to the cache storage
 * once the response has been sent.
 *
 * Flushed on kernel.terminate, which runs after the client has been released
 * (fastcgi_finish_request() under PHP-FPM and FrankenPHP, after the request in
 * FrankenPHP worker mode), and on kernel.reset so a long-running worker never
 * carries a write over to the next request.
 *
 * A failed upload is logged, not thrown: the client already has the image, and
 * the next request for the variant renders it again. The local file is deleted
 * either way, so a long-running worker never accumulates them.
 */
final class DeferredCacheWriter implements ResetInterface
{
    /**
     * @var list<array{from: FilesystemOperator, to: FilesystemOperator, path: string}>
     */
    private array $pending = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Move $path from $from to $to (same path on both) on the next flush().
     */
    public function defer(FilesystemOperator $from, FilesystemOperator $to, string $path): void
    {
        $this->pending[] = ['from' => $from, 'to' => $to, 'path' => $path];
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as ['from' => $from, 'to' => $to, 'path' => $path]) {
            try {
                $this->move($from, $to, $path);
            } catch (FilesystemException $e) {
                // Concurrent requests for the same variant all render and upload
                // it; object stores may reject the losers. Nothing is lost when
                // the variant is there.
                if (!$this->isStored($to, $path)) {
                    $this->logger?->error('Picasso could not store the rendered image "{path}" in the Glide cache: {message}', [
                        'path' => $path,
                        'message' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                }
            } finally {
                try {
                    $from->delete($path);
                } catch (FilesystemException) {
                    // Already gone: nothing to clean up
                }
            }
        }
    }

    public function reset(): void
    {
        $this->flush();
    }

    private function move(FilesystemOperator $from, FilesystemOperator $to, string $path): void
    {
        $stream = $from->readStream($path);

        try {
            $to->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function isStored(FilesystemOperator $storage, string $path): bool
    {
        try {
            return $storage->fileExists($path);
        } catch (FilesystemException) {
            return false;
        }
    }
}
