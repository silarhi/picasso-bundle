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
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubServiceTransformer;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\UX\TwigComponent\TwigComponentBundle;

/**
 * Kernel that exercises the full breadth of configuration options:
 * - Multiple filesystem loaders with different names
 * - Disabled loader
 * - Imgix transformer alongside Glide
 * - Service-type transformer
 * - Disabled transformer
 * - Custom device_sizes, image_sizes, formats, quality, fit
 * - Custom blur placeholder settings
 * - Explicit default_loader / default_transformer
 * - max_image_size on Glide
 */
class FullConfigKernel extends Kernel
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
                'secret' => 'full-config-secret',
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
                'default_loader' => 'main',
                'default_transformer' => 'local_glide',
                'device_sizes' => [320, 640, 1024],
                'image_sizes' => [24, 48, 96],
                'formats' => ['webp', 'png'],
                'default_quality' => 90,
                'default_fit' => 'cover',
                'placeholders' => [
                    'blur' => [
                        'enabled' => true,
                        'size' => 20,
                        'blur' => 10,
                        'quality' => 50,
                    ],
                ],
                'loaders' => [
                    'main' => [
                        'type' => 'filesystem',
                        'paths' => [dirname(__DIR__) . '/Fixtures'],
                    ],
                    'secondary_fs' => [
                        'type' => 'filesystem',
                        'paths' => [dirname(__DIR__) . '/Fixtures'],
                    ],
                    'third_fs' => [
                        'type' => 'filesystem',
                        'paths' => [dirname(__DIR__) . '/Fixtures', dirname(__DIR__, 2) . '/templates'],
                    ],
                    'disabled_loader' => [
                        'type' => 'filesystem',
                        'enabled' => false,
                        'paths' => ['/nonexistent'],
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

            // Register the stub service transformer
            $container->register(StubServiceTransformer::class, StubServiceTransformer::class);
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
        return sys_get_temp_dir() . '/picasso_test/cache/full';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/picasso_test/log';
    }
}
