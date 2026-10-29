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

class PublicCacheKernel extends AbstractPicassoKernel
{
    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('picasso', [
            'loaders' => [
                'filesystem' => [
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'public-cache-key_0123456789abcdef0123456789abcdef',
                    'cache' => '%kernel.cache_dir%/glide',
                    'public_cache' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ]);
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 2) . '/var/test/cache/public_cache';
    }
}
