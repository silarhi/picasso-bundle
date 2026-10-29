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

use Silarhi\PicassoBundle\PicassoBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A headless application: no TwigBundle nor TwigComponentBundle.
 */
class NoTwigKernel extends AbstractPicassoKernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new PicassoBundle(),
        ];
    }

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
                    'sign_key' => 'no-twig-test-key_0123456789abcdef0123456789abcdef',
                    'cache' => '%kernel.cache_dir%/glide',
                ],
            ],
        ]);
    }
}
