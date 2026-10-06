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

use Silarhi\PicassoBundle\Tests\Functional\Stub\PrivateDocumentController;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A private loader whose images only an application route serves, through
 * ImageServer, next to a public loader served by the bundle route.
 */
class PrivateRouteKernel extends AbstractPicassoKernel
{
    public const SIGN_KEY = 'private-route-key';

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'router' => [
                'resource' => __DIR__ . '/private_routes.php',
            ],
        ]);

        $container->loadFromExtension('picasso', [
            'default_loader' => 'documents',
            'loaders' => [
                'documents' => [
                    'type' => 'filesystem',
                    'path' => dirname(__DIR__) . '/Fixtures',
                    'private' => true,
                ],
                'public' => [
                    'type' => 'filesystem',
                    'path' => dirname(__DIR__) . '/Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => self::SIGN_KEY,
                    'cache' => '%kernel.cache_dir%/glide',
                ],
            ],
            'placeholders' => [
                'blur' => ['type' => 'transformer'],
            ],
        ]);

        $container->register(PrivateDocumentController::class)
            ->setAutowired(true)
            ->setPublic(true)
            ->addTag('controller.service_arguments');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/picasso_test/cache/private_route';
    }
}
