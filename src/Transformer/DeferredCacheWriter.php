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

use function is_resource;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

use function spl_object_id;

use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockInterface;
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
 * either way, and so is the render directory once everything in it is moved:
 * renders leave Glide's variant folders behind, and every PHP-FPM request gets a
 * directory of its own, so neither may accumulate.
 *
 * A render lock (lock option) is released once its variant is stored: until then,
 * the requests waiting for that render would not find it in the cache storage.
 *
 * Part of GlideTransformer's defer_cache_write, not a general-purpose service:
 * both storages are Glide cache storages, which Glide requires to be Flysystem.
 *
 * @internal
 */
final class DeferredCacheWriter implements ResetInterface
{
    /**
     * @var list<array{from: FilesystemOperator, to: FilesystemOperator, path: string, lock: LockInterface|null}>
     */
    private array $pending = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Move $path from $from to $to (same path on both) on the next flush().
     *
     * @param FilesystemOperator $from A render directory private to its transformer: it is deleted whole on flush()
     * @param LockInterface|null $lock Released once the variant is stored
     */
    public function defer(FilesystemOperator $from, FilesystemOperator $to, string $path, ?LockInterface $lock = null): void
    {
        $this->pending[] = ['from' => $from, 'to' => $to, 'path' => $path, 'lock' => $lock];
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $renderDirectories = [];

        foreach ($pending as ['from' => $from, 'to' => $to, 'path' => $path, 'lock' => $lock]) {
            $renderDirectories[spl_object_id($from)] = $from;

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

                $this->release($lock);
            }
        }

        // Only once every render is moved: several may share a directory
        foreach ($renderDirectories as $renderDirectory) {
            try {
                $renderDirectory->deleteDirectory('');
            } catch (FilesystemException) {
                // The next flush tries again
            }
        }
    }

    public function reset(): void
    {
        $this->flush();
    }

    private function release(?LockInterface $lock): void
    {
        try {
            $lock?->release();
        } catch (LockReleasingException $e) {
            // It expires on its own (lock ttl); waiting requests render the variant meanwhile
            $this->logger?->warning('Picasso could not release a render lock: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
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
