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

use Silarhi\PicassoBundle\PicassoBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class CollectorKernel extends AbstractPicassoKernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new PicassoBundle(),
        ];
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('picasso', [
            'collector' => true,
            'cache' => false,
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'integration-test-key',
                    'cache' => '%kernel.cache_dir%/glide',
                ],
            ],
        ]);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/picasso_test/collector_cache/' . $this->environment;
    }
}
