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

namespace Silarhi\PicassoBundle\Benchmarks;

use Silarhi\PicassoBundle\Tests\Functional\AbstractPicassoKernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A production kernel (no debug) configured from benchmark options:
 *
 *   driver      gd|imagick|vips
 *   storage     local|counting (counting: CountingStorage, optionally slowed down to mimic an object store)
 *   latency_us  per-call latency of the counting storage
 *   public      public_cache.enabled
 *   defer       defer_cache_write
 *   lock        lock.enabled (flock store)
 *
 * @phpstan-type BenchOptions array{driver: string, storage: string, latency_us: int, public: bool, defer: bool, lock: bool}
 */
final class BenchKernel extends AbstractPicassoKernel
{
    public const DEFAULTS = ['driver' => 'gd', 'storage' => 'local', 'latency_us' => 0, 'public' => false, 'defer' => false, 'lock' => false];

    /** @var BenchOptions */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        /** @var BenchOptions $merged */
        $merged = [...self::DEFAULTS, ...$options];
        $this->options = $merged;

        parent::__construct('prod', false);
    }

    public static function varDir(): string
    {
        return __DIR__ . '/var';
    }

    public static function imagesDir(): string
    {
        return self::varDir() . '/images';
    }

    /**
     * Glide cache of this configuration; the herd scenario counts writes in it.
     */
    public function glideCacheDir(): string
    {
        return self::varDir() . '/glide-' . $this->fingerprint();
    }

    public function writeLog(): string
    {
        return $this->glideCacheDir() . '.writes';
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $counting = 'counting' === $this->options['storage'];
        if ($counting) {
            $container->register('bench.cache_storage', CountingStorage::class)
                ->setArguments([$this->glideCacheDir(), $this->options['latency_us'], $this->writeLog()])
                ->setPublic(true)
                ->addTag('flysystem.storage', ['storage' => 'bench.cache']);
        }

        if ($this->options['lock']) {
            $container->loadFromExtension('framework', ['lock' => 'flock://' . self::varDir() . '/locks']);
        }

        $container->loadFromExtension('framework', ['cache' => ['app' => 'cache.adapter.array']]);
        $container->loadFromExtension('picasso', [
            'loaders' => ['main' => ['type' => 'filesystem', 'path' => self::imagesDir()]],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'benchmark-sign-key',
                    'cache' => $counting ? 'bench.cache' : $this->glideCacheDir(),
                    'driver' => $this->options['driver'],
                    'defer_cache_write' => $this->options['defer'],
                    'public_cache' => ['enabled' => $this->options['public']],
                    'lock' => ['enabled' => $this->options['lock']],
                ],
            ],
        ]);
    }

    public function getCacheDir(): string
    {
        return self::varDir() . '/kernel-' . $this->fingerprint();
    }

    public function getLogDir(): string
    {
        return self::varDir() . '/log';
    }

    private function fingerprint(): string
    {
        return substr(md5((string) json_encode($this->options)), 0, 10);
    }
}
