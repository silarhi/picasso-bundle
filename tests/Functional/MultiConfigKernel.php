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

use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributeLoader;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributePlaceholder;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributeTransformer;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class MultiConfigKernel extends AbstractPicassoKernel
{
    protected function configureContainer(ContainerBuilder $container): void
    {
        // Multiple loaders of the same type, attribute-registered stubs, etc.
        $container->loadFromExtension('picasso', [
            'default_loader' => 'primary',
            'default_transformer' => 'glide',
            'default_placeholder' => 'blur',
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                ],
            ],
            'loaders' => [
                'primary' => [
                    'type' => 'filesystem',
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
                'secondary' => [
                    'type' => 'filesystem',
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
                'disabled_loader' => [
                    'type' => 'filesystem',
                    'enabled' => false,
                    'paths' => ['/nonexistent'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'multi-test-key',
                    'cache' => '%kernel.cache_dir%/glide',
                ],
            ],
        ]);

        // Register attribute-based stubs as services
        $container->register(StubAttributeLoader::class, StubAttributeLoader::class)->setAutoconfigured(true);
        $container->register(StubAttributeTransformer::class, StubAttributeTransformer::class)->setAutoconfigured(true);
        $container->register(StubAttributePlaceholder::class, StubAttributePlaceholder::class)->setAutoconfigured(true);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/picasso_test/cache/multi';
    }
}
