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

use function array_slice;
use function assert;
use function dirname;

use League\FlysystemBundle\FlysystemBundle;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\DataCollector\CollectingImageHelper;
use Silarhi\PicassoBundle\DataCollector\CollectingMetadataGuesser;
use Silarhi\PicassoBundle\DataCollector\PicassoDataCollector;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\PicassoBundle;
use Silarhi\PicassoBundle\Placeholder\BlurHashPlaceholder;
use Silarhi\PicassoBundle\Placeholder\PlaceholderInterface;
use Silarhi\PicassoBundle\Placeholder\TransformerPlaceholder;
use Silarhi\PicassoBundle\Service\DeferredCacheWriter;
use Silarhi\PicassoBundle\Service\ImageHelper;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\MetadataGuesser;
use Silarhi\PicassoBundle\Service\PlaceholderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributePlaceholder;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributeTransformer;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubConfiguredAttributeLoader;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubServicePlaceholder;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubServiceTransformer;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\ImgixTransformer;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Vich\UploaderBundle\VichUploaderBundle;

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
        ], withPsrHttpClient: true);

        self::assertTrue($container->has('picasso.loader.url'));
    }

    public function testGlideCdnOptionsAreWired(): void
    {
        $container = $this->loadExtension([
            'cache_control' => ['max_age' => 600, 'immutable' => false, 'error_max_age' => 60],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'secret',
                    'base_url' => 'https://cdn.example.com',
                    'defer_cache_write' => true,
                    'public_cache' => ['enabled' => true, 'prefix' => 'image'],
                ],
            ],
        ]);

        $transformer = $container->getDefinition('picasso.transformer.glide');
        self::assertSame('https://cdn.example.com', $transformer->getArgument(8));
        self::assertSame('image', $transformer->getArgument(9));
        self::assertEquals(new Reference('picasso.deferred_cache_writer'), $transformer->getArgument(10));

        $writer = $container->getDefinition('picasso.deferred_cache_writer');
        self::assertSame(DeferredCacheWriter::class, $writer->getClass());
        self::assertSame([['event' => 'kernel.terminate', 'method' => 'flush']], $writer->getTag('kernel.event_listener'));
        self::assertSame([['method' => 'reset']], $writer->getTag('kernel.reset'));

        self::assertSame(
            ['max_age' => 600, 'immutable' => false, 'error_max_age' => 60],
            $container->getDefinition('picasso.controller.image')->getArgument(3),
        );
    }

    public function testGlideCdnOptionsAreOffByDefault(): void
    {
        $container = $this->loadExtension([
            'transformers' => [
                'glide' => ['sign_key' => 'secret'],
            ],
        ]);

        $transformer = $container->getDefinition('picasso.transformer.glide');
        self::assertNull($transformer->getArgument(8));
        self::assertSame('', $transformer->getArgument(9));
        self::assertNull($transformer->getArgument(10));
        self::assertFalse($container->hasDefinition('picasso.deferred_cache_writer'));
        self::assertSame(
            ['max_age' => 31536000, 'immutable' => true, 'error_max_age' => null],
            $container->getDefinition('picasso.controller.image')->getArgument(3),
        );
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

    public function testVichLoaderWithFlysystemStorageIsRegistered(): void
    {
        $kernel = $this->bootVichKernel();

        $container = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        self::assertTrue($container->has('picasso.loader.vich'));
        self::assertInstanceOf(VichUploaderLoader::class, $container->get('picasso.loader.vich'));
    }

    public function testVichHelperAndFlysystemRegistryAreRegistered(): void
    {
        $kernel = $this->bootVichKernel();

        $container = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        self::assertTrue($container->has('.picasso.vich_mapping_helper'));
        self::assertTrue($container->has('.picasso.flysystem_registry'));
    }

    // --- Service-type transformer wiring ---

    public function testServiceTransformerIsRegistered(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'custom' => [
                    'type' => 'service',
                    'service' => StubServiceTransformer::class,
                ],
            ],
        ], withServices: [StubServiceTransformer::class]);

        self::assertTrue($container->has('picasso.transformer.custom'));
        self::assertInstanceOf(StubServiceTransformer::class, $container->get('picasso.transformer.custom'));
    }

    public function testDisabledTransformerIsNotRegistered(): void
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
                'disabled_one' => [
                    'type' => 'glide',
                    'enabled' => false,
                ],
            ],
        ]);

        self::assertTrue($container->has('picasso.transformer.glide'));
        self::assertFalse($container->has('picasso.transformer.disabled_one'));
    }

    // --- Placeholder wiring ---

    public function testTransformerPlaceholderIsRegistered(): void
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
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                    'size' => 20,
                    'blur' => 10,
                    'quality' => 50,
                ],
            ],
        ]);

        self::assertTrue($container->has('picasso.placeholder.blur'));
        self::assertInstanceOf(TransformerPlaceholder::class, $container->get('picasso.placeholder.blur'));
    }

    public function testBlurhashPlaceholderIsRegistered(): void
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
            'placeholders' => [
                'hash' => [
                    'type' => 'blurhash',
                    'components_x' => 4,
                    'components_y' => 3,
                    'size' => 16,
                ],
            ],
        ]);

        self::assertTrue($container->has('picasso.placeholder.hash'));
        self::assertInstanceOf(BlurHashPlaceholder::class, $container->get('picasso.placeholder.hash'));
    }

    public function testServicePlaceholderIsRegistered(): void
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
            'placeholders' => [
                'custom' => [
                    'type' => 'service',
                    'service' => StubServicePlaceholder::class,
                ],
            ],
        ], withServices: [StubServicePlaceholder::class]);

        self::assertTrue($container->has('picasso.placeholder.custom'));
        self::assertInstanceOf(StubServicePlaceholder::class, $container->get('picasso.placeholder.custom'));
    }

    public function testDisabledPlaceholderIsNotRegistered(): void
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
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                    'enabled' => false,
                ],
            ],
        ]);

        self::assertFalse($container->has('picasso.placeholder.blur'));
    }

    public function testDefaultPlaceholderAutoDetectedWhenOnlyOneEnabled(): void
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
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                ],
            ],
        ]);

        self::assertTrue($container->has(PlaceholderInterface::class));
    }

    public function testPlaceholderWithUnknownTypeThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Placeholder "my_custom" must specify a "type"');

        $this->bootKernel([
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
            'placeholders' => [
                'my_custom' => [],
            ],
        ]);
    }

    // --- Flysystem loader wiring ---

    public function testFlysystemLoaderIsRegistered(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'cloud' => [
                    'type' => 'flysystem',
                    'storage' => 'test.flysystem.storage',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ], withFlysystemStorage: true);

        self::assertTrue($container->has('picasso.loader.cloud'));
        self::assertInstanceOf(FlysystemLoader::class, $container->get('picasso.loader.cloud'));
    }

    // --- Cache configuration ---

    public function testCacheDisabledSkipsCacheService(): void
    {
        $container = $this->bootKernel([
            'cache' => false,
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

        // MetadataGuesser should still be registered (without cache)
        self::assertTrue($container->has('picasso.metadata_guesser'));
    }

    public function testCacheCustomServiceId(): void
    {
        $container = $this->bootKernel([
            'cache' => 'cache.app',
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

        self::assertTrue($container->has('picasso.metadata_guesser'));
    }

    // --- Explicit default_placeholder ---

    public function testExplicitDefaultPlaceholder(): void
    {
        $container = $this->bootKernel([
            'default_placeholder' => 'blur',
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
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                ],
                'other' => [
                    'type' => 'transformer',
                ],
            ],
        ]);

        self::assertTrue($container->has(PlaceholderInterface::class));
        self::assertInstanceOf(TransformerPlaceholder::class, $container->get(PlaceholderInterface::class));
    }

    // --- Per-loader default_placeholder ---

    public function testLoaderDefaultPlaceholderIsAvailableInRegistry(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                    'default_placeholder' => 'blur',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                ],
            ],
        ]);

        $registry = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $registry);
        self::assertSame('blur', $registry->getDefaultPlaceholder('filesystem'));
    }

    public function testLoaderWithoutDefaultPlaceholderReturnsNull(): void
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

        $registry = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $registry);
        self::assertNull($registry->getDefaultPlaceholder('filesystem'));
    }

    // --- Per-loader default_transformer ---

    public function testLoaderDefaultTransformerIsAvailableInRegistry(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                    'default_transformer' => 'glide',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);

        $registry = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $registry);
        self::assertSame('glide', $registry->getDefaultTransformer('filesystem'));
    }

    public function testLoaderWithoutDefaultTransformerReturnsNull(): void
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

        $registry = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $registry);
        self::assertNull($registry->getDefaultTransformer('filesystem'));
    }

    // --- Per-loader resolve_metadata ---

    public function testFilesystemLoaderDefaultsResolveMetadataToTrue(): void
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

        $registry = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $registry);
        self::assertTrue($registry->getResolveMetadata('filesystem'));
    }

    public function testExplicitResolveMetadataPerLoader(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                    'resolve_metadata' => false,
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);

        $registry = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $registry);
        self::assertFalse($registry->getResolveMetadata('filesystem'));
    }

    public function testUrlLoaderResolveMetadataDefaultsToNull(): void
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
        ], withPsrHttpClient: true);

        $registry = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $registry);
        self::assertNull($registry->getResolveMetadata('url'));
    }

    // --- Invalid default references ---

    public function testDefaultLoaderReferencingNonexistentLoaderThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The default loader "nonexistent" does not exist.');

        $this->bootKernel([
            'default_loader' => 'nonexistent',
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
    }

    public function testDefaultTransformerReferencingNonexistentTransformerThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The default transformer "nonexistent" does not exist.');

        $this->bootKernel([
            'default_transformer' => 'nonexistent',
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
    }

    public function testDefaultPlaceholderReferencingNonexistentPlaceholderThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The default placeholder "nonexistent" does not exist.');

        $this->bootKernel([
            'default_placeholder' => 'nonexistent',
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
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                ],
            ],
        ]);
    }

    public function testDefaultLoaderReferencingDisabledLoaderThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The default loader "disabled_fs" is disabled.');

        $this->bootKernel([
            'default_loader' => 'disabled_fs',
            'loaders' => [
                'disabled_fs' => [
                    'type' => 'filesystem',
                    'enabled' => false,
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);
    }

    public function testDefaultTransformerReferencingDisabledTransformerThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The default transformer "disabled_glide" is disabled.');

        $this->bootKernel([
            'default_transformer' => 'disabled_glide',
            'loaders' => [
                'filesystem' => [
                    'paths' => [dirname(__DIR__) . '/Fixtures'],
                ],
            ],
            'transformers' => [
                'disabled_glide' => [
                    'type' => 'glide',
                    'enabled' => false,
                    'sign_key' => 'test',
                ],
            ],
        ]);
    }

    public function testDefaultPlaceholderReferencingDisabledPlaceholderThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The default placeholder "disabled_blur" is disabled.');

        $this->bootKernel([
            'default_placeholder' => 'disabled_blur',
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
            'placeholders' => [
                'disabled_blur' => [
                    'type' => 'transformer',
                    'enabled' => false,
                ],
            ],
        ]);
    }

    // --- Attribute autoconfiguration ---

    public function testAttributeTaggedServicesAreRegisteredWithTheirLoaderDefaults(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [__DIR__ . '/../Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ], withServices: [StubConfiguredAttributeLoader::class, StubAttributeTransformer::class, StubAttributePlaceholder::class]);

        $loaders = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $loaders);
        self::assertInstanceOf(StubConfiguredAttributeLoader::class, $loaders->get('configured'));
        self::assertSame('stub', $loaders->getDefaultPlaceholder('configured'));
        self::assertSame('stub', $loaders->getDefaultTransformer('configured'));
        self::assertFalse($loaders->getResolveMetadata('configured'));

        $transformers = $container->get('picasso.transformer_registry');
        self::assertInstanceOf(TransformerRegistry::class, $transformers);
        self::assertInstanceOf(StubAttributeTransformer::class, $transformers->get('stub'));

        $placeholders = $container->get('picasso.placeholder_registry');
        self::assertInstanceOf(PlaceholderRegistry::class, $placeholders);
        self::assertInstanceOf(StubAttributePlaceholder::class, $placeholders->get('stub'));
    }

    public function testBuildDoesNotRequireTheExtensionToBeLoaded(): void
    {
        $container = new ContainerBuilder();
        (new PicassoBundle())->build($container);

        // The loader-defaults compiler pass must skip, not fail, without the registry
        $container->compile();

        self::assertFalse($container->hasDefinition('picasso.loader_registry'));
    }

    // --- Data collector ---

    public function testCollectorIsDisabledByDefault(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'paths' => [__DIR__ . '/../Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);

        self::assertFalse($container->has('picasso.data_collector'));
        self::assertInstanceOf(ImageHelper::class, $container->get('picasso.image_helper'));
        self::assertInstanceOf(MetadataGuesser::class, $container->get('picasso.metadata_guesser'));
    }

    public function testCollectorDecoratesImageHelperAndMetadataGuesserWhenEnabled(): void
    {
        $container = $this->bootKernel([
            'collector' => true,
            'loaders' => [
                'filesystem' => [
                    'paths' => [__DIR__ . '/../Fixtures'],
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test',
                ],
            ],
        ]);

        self::assertInstanceOf(PicassoDataCollector::class, $container->get('picasso.data_collector'));
        self::assertInstanceOf(CollectingImageHelper::class, $container->get('picasso.image_helper'));
        self::assertInstanceOf(CollectingMetadataGuesser::class, $container->get('picasso.metadata_guesser'));
    }

    // --- Definitions of services that cannot be instantiated everywhere ---

    /**
     * @return iterable<string, array{array<string, string>, string, string, string}>
     */
    public static function imgixPurgeServicesProvider(): iterable
    {
        yield 'default PSR-18 client' => [[], 'psr18.http_client', 'psr18.http_client', 'psr18.http_client'];
        yield 'factories default to the client' => [
            ['http_client' => 'app.http_client'],
            'app.http_client',
            'app.http_client',
            'app.http_client',
        ];
        yield 'dedicated factories' => [
            ['http_client' => 'app.http_client', 'request_factory' => 'app.request_factory', 'stream_factory' => 'app.stream_factory'],
            'app.http_client',
            'app.request_factory',
            'app.stream_factory',
        ];
    }

    /**
     * @param array<string, string> $purgeServices
     */
    #[DataProvider('imgixPurgeServicesProvider')]
    public function testImgixTransformerWithApiKeyIsWiredForPurging(array $purgeServices, string $httpClient, string $requestFactory, string $streamFactory): void
    {
        $container = $this->loadExtension([
            'transformers' => [
                'imgix' => [
                    'base_url' => 'https://example.imgix.net',
                    'sign_key' => 'secret',
                    'api_key' => 'imgix-api-key',
                    ...$purgeServices,
                ],
            ],
        ]);

        $arguments = $container->getDefinition('picasso.transformer.imgix')->getArguments();

        self::assertCount(6, $arguments);
        self::assertSame(['https://example.imgix.net', 'secret', 'imgix-api-key'], array_slice($arguments, 0, 3));
        self::assertEquals(new Reference($httpClient), $arguments[3]);
        self::assertEquals(new Reference($requestFactory), $arguments[4]);
        self::assertEquals(new Reference($streamFactory), $arguments[5]);
    }

    public function testImgixTransformerWithoutApiKeyHasNoPurgeServices(): void
    {
        $container = $this->loadExtension([
            'transformers' => [
                'imgix' => [
                    'base_url' => 'https://example.imgix.net',
                    'http_client' => 'app.http_client',
                ],
            ],
        ]);

        self::assertSame(
            ['https://example.imgix.net', null],
            $container->getDefinition('picasso.transformer.imgix')->getArguments(),
        );
    }

    public function testBlurhashPlaceholderWithImagickDriverUsesImagick(): void
    {
        // Imagick may not be installed: only the definitions are checked
        $container = $this->loadExtension([
            'placeholders' => [
                'hash' => [
                    'type' => 'blurhash',
                    'driver' => 'imagick',
                ],
            ],
        ]);

        self::assertSame(\Imagine\Imagick\Imagine::class, $container->getDefinition('picasso.imagine.hash')->getClass());
        self::assertEquals(new Reference('picasso.imagine.hash'), $container->getDefinition('picasso.placeholder.hash')->getArgument(0));
    }

    public function testBlurhashPlaceholderGetsNoCachePoolWhenCacheIsDisabled(): void
    {
        $container = $this->loadExtension([
            'cache' => false,
            'placeholders' => [
                'hash' => [
                    'type' => 'blurhash',
                ],
            ],
        ]);

        self::assertSame(\Imagine\Gd\Imagine::class, $container->getDefinition('picasso.imagine.hash')->getClass());
        self::assertNull($container->getDefinition('picasso.placeholder.hash')->getArgument(4));
    }

    /**
     * @param array<string, mixed> $picassoConfig
     * @param list<class-string>   $withServices
     */
    private function bootKernel(array $picassoConfig, bool $withPsrHttpClient = false, array $withServices = [], bool $withFlysystemStorage = false): ContainerInterface
    {
        $kernel = new BundleWiringTestKernel(
            'test_' . bin2hex(random_bytes(4)),
            false,
            $picassoConfig,
            $withPsrHttpClient,
            $withServices,
            $withFlysystemStorage,
        );
        $kernel->boot();
        $this->kernels[] = $kernel;

        $container = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }

    /**
     * Load the bundle extension into a bare container builder, without compiling
     * it, to check definitions whose services cannot be built in every
     * environment (optional PHP extensions, PSR-17 implementations).
     *
     * @param array<string, mixed> $picassoConfig
     */
    private function loadExtension(array $picassoConfig): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            'kernel.project_dir' => dirname(__DIR__, 2),
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.cache_dir' => sys_get_temp_dir(),
        ]));

        $bundle = new PicassoBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        assert($extension instanceof ExtensionInterface);
        $extension->load([$picassoConfig], $container);

        return $container;
    }

    private function bootVichKernel(): Kernel
    {
        $kernel = new VichWiringTestKernel('test_vich_' . bin2hex(random_bytes(4)), false);
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }
}

/**
 * @internal
 */
class BundleWiringTestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $picassoConfig
     * @param list<class-string>   $withServices
     */
    public function __construct(
        string $environment,
        bool $debug,
        private readonly array $picassoConfig,
        private readonly bool $withPsrHttpClient,
        private readonly array $withServices = [],
        private readonly bool $withFlysystemStorage = false,
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

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $picassoConfig = $this->picassoConfig;
        $withPsrHttpClient = $this->withPsrHttpClient;
        $withServices = $this->withServices;
        $withFlysystemStorage = $this->withFlysystemStorage;

        $loader->load(static function (ContainerBuilder $container) use ($picassoConfig, $withPsrHttpClient, $withServices, $withFlysystemStorage): void {
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

            if ($withPsrHttpClient) {
                $frameworkConfig['http_client'] = ['enabled' => true];
            }

            $container->loadFromExtension('framework', $frameworkConfig);

            $container->loadFromExtension('twig', [
                'default_path' => '%kernel.project_dir%/templates',
            ]);

            $container->loadFromExtension('picasso', $picassoConfig);

            foreach ($withServices as $serviceClass) {
                // Autoconfigured like in an application, so #[As*] attributes apply
                $container->register($serviceClass, $serviceClass)->setAutoconfigured(true);
            }

            if ($withFlysystemStorage) {
                $container->register('test.flysystem.storage', \League\Flysystem\Filesystem::class)
                    ->addArgument(new \Symfony\Component\DependencyInjection\Definition(
                        \League\Flysystem\Local\LocalFilesystemAdapter::class,
                        [dirname(__DIR__) . '/Fixtures'],
                    ));
            }
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

/**
 * Kernel that configures VichUploaderBundle with Flysystem storage
 * and Picasso's vich loader, using a Flysystem storage service name as upload_destination.
 *
 * @internal
 */
class VichWiringTestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new FlysystemBundle(),
            new VichUploaderBundle(),
            new PicassoBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $fixturesDir = dirname(__DIR__) . '/Fixtures';

        $loader->load(static function (ContainerBuilder $container) use ($fixturesDir): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'secret' => 'test-secret',
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

            // Configure FlysystemBundle with a local adapter
            $container->loadFromExtension('flysystem', [
                'storages' => [
                    'products.storage' => [
                        'adapter' => 'local',
                        'options' => [
                            'directory' => $fixturesDir,
                        ],
                    ],
                ],
            ]);

            // Configure VichUploaderBundle with flysystem storage,
            // using the Flysystem storage service name as upload_destination
            $container->loadFromExtension('vich_uploader', [
                'db_driver' => 'orm',
                'storage' => 'flysystem',
                'mappings' => [
                    'product_image' => [
                        'upload_destination' => 'products.storage',
                        'uri_prefix' => '/uploads/products',
                    ],
                ],
            ]);

            // Configure Picasso with a vich loader
            $container->loadFromExtension('picasso', [
                'loaders' => [
                    'vich' => [],
                ],
                'transformers' => [
                    'glide' => [
                        'sign_key' => 'test-key',
                        'cache' => '%kernel.cache_dir%/glide',
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
        return sys_get_temp_dir() . '/picasso_vich_test/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/picasso_vich_test/log';
    }
}
