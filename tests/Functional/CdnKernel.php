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

namespace Silarhi\PicassoBundle\Tests\Functional;

use function dirname;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Glide set up to sit behind a CDN whose origin is the cache bucket: URLs on the
 * CDN host, cache keys mirroring the URL path, misses stored after the response
 * is sent and 404s cacheable for a minute.
 */
class CdnKernel extends AbstractPicassoKernel
{
    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('picasso', [
            'cache_control' => [
                'error_max_age' => 60,
            ],
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'cdn-key',
                    'cache' => '%kernel.cache_dir%/bucket',
                    'base_url' => 'https://cdn.example.com',
                    'defer_cache_write' => true,
                    'public_cache' => [
                        'enabled' => true,
                        'prefix' => 'image',
                    ],
                ],
            ],
        ]);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/picasso_test/cache/cdn';
    }
}
