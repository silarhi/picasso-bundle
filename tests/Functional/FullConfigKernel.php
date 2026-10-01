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

use Silarhi\PicassoBundle\Tests\Functional\Stub\StubServicePlaceholder;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubServiceTransformer;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel that exercises the full breadth of configuration options:
 * - Multiple filesystem loaders with different names
 * - Disabled loader
 * - Chain loader
 * - Imgix transformer alongside Glide
 * - Service-type transformer
 * - Disabled transformer
 * - Custom device_sizes, image_sizes, formats, quality, fit
 * - Custom blur placeholder settings
 * - Explicit default_loader / default_transformer
 * - max_image_size on Glide
 */
class FullConfigKernel extends AbstractPicassoKernel
{
    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('picasso', [
            'default_loader' => 'main',
            'default_transformer' => 'local_glide',
            'default_placeholder' => 'blur',
            'device_sizes' => [320, 640, 1024],
            'image_sizes' => [24, 48, 96],
            'formats' => ['webp', 'png'],
            'default_quality' => 90,
            'default_fit' => 'cover',
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                    'size' => 20,
                    'blur' => 10,
                    'quality' => 50,
                ],
                'custom_placeholder' => [
                    'type' => 'service',
                    'service' => StubServicePlaceholder::class,
                ],
            ],
            'loaders' => [
                'main' => [
                    'type' => 'filesystem',
                    'path' => dirname(__DIR__) . '/Fixtures',
                ],
                'secondary_fs' => [
                    'type' => 'filesystem',
                    'path' => dirname(__DIR__) . '/Fixtures',
                ],
                'third_fs' => [
                    'type' => 'filesystem',
                    'path' => dirname(__DIR__) . '/Fixtures',
                ],
                'disabled_loader' => [
                    'type' => 'filesystem',
                    'enabled' => false,
                    'path' => '/nonexistent',
                ],
                // Holds no image: the chain falls through to "main"
                'entities_fs' => [
                    'type' => 'filesystem',
                    'path' => dirname(__DIR__) . '/Fixtures/Entity',
                ],
                'chained' => [
                    'type' => 'chain',
                    'loaders' => ['entities_fs', 'main'],
                ],
            ],
            'transformers' => [
                'local_glide' => [
                    'type' => 'glide',
                    'sign_key' => 'full-test-key',
                    'cache' => '%kernel.cache_dir%/glide',
                    'driver' => 'gd',
                    'max_image_size' => 4194304,
                ],
                'cdn_imgix' => [
                    'type' => 'imgix',
                    'base_url' => 'https://test.imgix.net',
                    'sign_key' => 'imgix-sign-key',
                ],
                'imgix_unsigned' => [
                    'type' => 'imgix',
                    'base_url' => 'https://unsigned.imgix.net',
                ],
                'custom_service' => [
                    'type' => 'service',
                    'service' => StubServiceTransformer::class,
                ],
                'disabled_transformer' => [
                    'type' => 'glide',
                    'enabled' => false,
                ],
            ],
        ]);

        // Register stub services
        $container->register(StubServiceTransformer::class, StubServiceTransformer::class);
        $container->register(StubServicePlaceholder::class, StubServicePlaceholder::class);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/picasso_test/cache/full';
    }
}
