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

use function assert;
use function dirname;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\UrlLoader;
use Silarhi\PicassoBundle\PicassoBundle;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\ImgixTransformer;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

class BundleWiringTest extends TestCase
{
    /** @var Kernel[] */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
    }

    public function testLoaderWithUnknownTypeThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Loader "my_custom" must specify a "type"');

        $this->bootKernel([
            'loaders' => [
                'my_custom' => [
                    'paths' => ['/tmp'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);
    }

    public function testTransformerWithUnknownTypeThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Transformer "my_custom" must specify a "type"');

        $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'my_custom' => [],
            ],
        ]);
    }

    public function testDefaultLoaderAutoDetectedWhenOnlyOneEnabled(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);

        self::assertTrue($container->has(ImageLoaderInterface::class));
    }

    public function testDefaultTransformerAutoDetectedWhenOnlyOneEnabled(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);

        self::assertTrue($container->has(ImageTransformerInterface::class));
    }

    public function testNoDefaultLoaderWhenMultipleEnabled(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'images_a' => [
                    'type' => 'filesystem',
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
                'images_b' => [
                    'type' => 'filesystem',
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);

        self::assertFalse($container->has(ImageLoaderInterface::class));
    }

    public function testNoDefaultTransformerWhenMultipleEnabled(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
                'imgix' => [
                    'base_url' => 'https://example.imgix.net',
                ],
            ],
        ]);

        self::assertFalse($container->has(ImageTransformerInterface::class));
    }

    public function testDisabledLoaderIsNotRegistered(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
                'disabled_one' => [
                    'type' => 'filesystem',
                    'enabled' => false,
                    'paths' => ['/nonexistent'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);

        self::assertTrue($container->has(ImageLoaderInterface::class));
        self::assertFalse($container->has('picasso.loader.disabled_one'));
    }

    public function testUrlLoaderIsRegistered(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'url' => [],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ], withHttpClient: true);

        self::assertTrue($container->has('picasso.loader.url'));
        self::assertInstanceOf(UrlLoader::class, $container->get('picasso.loader.url'));
    }

    public function testImgixTransformerIsRegistered(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'imgix' => [
                    'base_url' => 'https://example.imgix.net',
                    'sign_key' => 'secret',
                ],
            ],
        ]);

        self::assertTrue($container->has('picasso.transformer.imgix'));
        self::assertInstanceOf(ImgixTransformer::class, $container->get('picasso.transformer.imgix'));
    }

    /**
     * @param array<string, mixed> $picassoConfig
     */
    private function bootKernel(array $picassoConfig, bool $withHttpClient = false): ContainerInterface
    {
        $kernel = new BundleWiringTestKernel(
            'test_' . bin2hex(random_bytes(4)),
            false,
            $picassoConfig,
            $withHttpClient,
        );
        $kernel->boot();
        $this->kernels[] = $kernel;

        $container = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }
}

/**
 * @internal
 */
class BundleWiringTestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $picassoConfig
     */
    public function __construct(
        string $environment,
        bool $debug,
        private readonly array $picassoConfig,
        private readonly bool $withHttpClient,
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new PicassoBundle(),
        ];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
        $picassoConfig = $this->picassoConfig;
        $withHttpClient = $this->withHttpClient;

        $loader->load(static function (ContainerBuilder $container) use ($picassoConfig, $withHttpClient): void {
            $frameworkConfig = [
                'test' => true,
                'secret' => 'test-secret',
                'router' => [
                    'resource' => '%kernel.project_dir%/config/routes.php',
                ],
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ];

            if ($withHttpClient) {
                $frameworkConfig['http_client'] = ['enabled' => true];
            }

            $container->loadFromExtension('framework', $frameworkConfig);

            $container->loadFromExtension('twig', [
                'default_path' => '%kernel.project_dir%/templates',
            ]);

            $container->loadFromExtension('picasso', $picassoConfig);
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
        return sys_get_temp_dir() . '/picasso_wiring_test/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/picasso_wiring_test/log';
    }
}
