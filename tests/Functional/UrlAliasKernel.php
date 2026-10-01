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
 * Glide with a public cache mirroring the URL path, where the transformer and
 * the loader are named by their URL alias.
 */
class UrlAliasKernel extends AbstractPicassoKernel
{
    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('picasso', [
            'loaders' => [
                'product_image' => [
                    'type' => 'filesystem',
                    'path' => dirname(__DIR__) . '/Fixtures',
                    'url_alias' => 'pi',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'url-alias-key',
                    'cache' => '%kernel.cache_dir%/bucket',
                    'url_alias' => 'g',
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
        return sys_get_temp_dir() . '/picasso_test/cache/url_alias';
    }
}
