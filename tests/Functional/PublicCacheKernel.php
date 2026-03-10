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
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\UX\TwigComponent\TwigComponentBundle;

class PublicCacheKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new TwigComponentBundle(),
            new PicassoBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'secret' => 'public-cache-secret',
                'router' => [
                    'resource' => '%kernel.project_dir%/config/routes.php',
                ],
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);

            $container->loadFromExtension('twig', [
                'default_path' => '%kernel.project_dir%/templates',
            ]);

            $container->loadFromExtension('picasso', [
                'loaders' => [
                    'filesystem' => [
                        'paths' => [dirname(__DIR__) . '/Fixtures'],
                    ],
                ],
                'transformers' => [
                    'glide' => [
                        'sign_key' => 'public-cache-key',
                        'cache' => '%kernel.cache_dir%/glide',
                        'public_cache' => [
                            'enabled' => true,
                        ],
                    ],
                ],
            ]);
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'picasso.') || str_starts_with($id, '.picasso.')) {
                        $definition->setPublic(true);
                    }
                }
            }
        });
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/picasso_test/cache/public_cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/picasso_test/log';
    }
}
