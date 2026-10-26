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
 * Glide serving the images AssetMapper maps from the fixtures directory, under the "images" namespace.
 */
class AssetMapperKernel extends AbstractPicassoKernel
{
    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            // FrameworkBundle 6.4.0 wires the importmap resolver of AssetMapper on http_client without enabling it
            'http_client' => ['enabled' => true],
            'asset_mapper' => [
                'paths' => [__DIR__ . '/../Fixtures' => 'images'],
            ],
        ]);

        $container->loadFromExtension('picasso', [
            'default_loader' => 'asset_mapper',
            'loaders' => [
                'asset_mapper' => [],
                'uploads' => [
                    'type' => 'filesystem',
                    'path' => __DIR__ . '/../Fixtures/Entity',
                ],
                'all' => [
                    'type' => 'chain',
                    'loaders' => ['uploads', 'asset_mapper'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'asset-mapper-key',
                    'cache' => '%kernel.cache_dir%/glide',
                ],
            ],
        ]);
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 2) . '/var/test/cache/asset_mapper';
    }
}
