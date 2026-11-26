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
 * Modifiers, with Glide watermarks.
 */
class AuthoringKernel extends AbstractPicassoKernel
{
    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('picasso', [
            'default_placeholder' => 'blur',
            'device_sizes' => [320, 640],
            'image_sizes' => [48],
            'formats' => ['webp', 'jpg'],
            'placeholders' => [
                'blur' => ['type' => 'transformer', 'size' => 10, 'blur' => 5],
            ],
            'loaders' => [
                'filesystem' => [
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'default_transformer' => 'glide',
            'transformers' => [
                'glide' => [
                    'sign_key' => 'authoring-test-key_0123456789abcdef0123456789abcdef',
                    'cache' => '%kernel.cache_dir%/glide',
                    'watermarks' => __DIR__ . '/../Fixtures',
                ],
                'imgix' => [
                    'base_url' => 'https://test.imgix.net',
                ],
            ],
        ]);
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 2) . '/var/test/authoring_cache/' . $this->environment;
    }
}
