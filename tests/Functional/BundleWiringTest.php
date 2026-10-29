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
use PHPUnit\Framework\Attributes\RequiresPhpunit;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Controller\LegacyUrlController;
use Silarhi\PicassoBundle\DataCollector\CollectingImageHelper;
use Silarhi\PicassoBundle\DataCollector\CollectingMetadataGuesser;
use Silarhi\PicassoBundle\DataCollector\PicassoDataCollector;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Loader\ChainLoader;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\PicassoBundle;
use Silarhi\PicassoBundle\Placeholder\BlurHashPlaceholder;
use Silarhi\PicassoBundle\Placeholder\PlaceholderInterface;
use Silarhi\PicassoBundle\Placeholder\TransformerPlaceholder;
use Silarhi\PicassoBundle\Service\ImageHelper;
use Silarhi\PicassoBundle\Service\ImageServer;
use Silarhi\PicassoBundle\Service\LegacyMetadataResolver;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\MetadataGuesser;
use Silarhi\PicassoBundle\Service\PlaceholderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Silarhi\PicassoBundle\Source\FlysystemImageSource;
use Silarhi\PicassoBundle\Tests\Fixtures\HidesFromAutoloadingTrait;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributePlaceholder;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubAttributeTransformer;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubConfiguredAttributeLoader;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubServicePlaceholder;
use Silarhi\PicassoBundle\Tests\Functional\Stub\StubServiceTransformer;
use Silarhi\PicassoBundle\Transformer\DeferredCacheWriter;
use Silarhi\PicassoBundle\Transformer\GlideSignature;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\ImgixTransformer;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Vich\UploaderBundle\VichUploaderBundle;

class BundleWiringTest extends TestCase
{
    use HidesFromAutoloadingTrait;

    private const SIGN_KEY = 'bundle-wiring-sign-key-0123456789abcdef';

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
                    'path' => '/tmp',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
                ],
            ],
        ]);
    }

    public function testFilesystemLoaderRequiresAPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "uploads": a filesystem loader requires a "path", the directory it reads images from');

        $this->loadExtension(['loaders' => ['uploads' => ['type' => 'filesystem']]]);
    }

    public function testPathIsOnlySupportedByFilesystemLoaders(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "vich": the "path" option is only supported by filesystem loaders.');

        $this->loadExtension(['loaders' => ['vich' => ['path' => '/tmp']]]);
    }

    public function testMappingIsOnlySupportedByVichLoaders(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "filesystem": the "mapping" option is only supported by vich loaders.');

        $this->loadExtension(['loaders' => ['filesystem' => ['path' => '/tmp', 'mapping' => 'product_image']]]);
    }

    public function testChainLoaderIsWired(): void
    {
        $container = $this->loadExtension(['loaders' => [
            'uploads' => ['type' => 'filesystem', 'path' => '/uploads'],
            'assets' => ['type' => 'filesystem', 'path' => '/assets'],
            'filesystem' => ['type' => 'chain', 'loaders' => ['uploads', 'assets']],
        ]]);

        $definition = $container->getDefinition('picasso.loader.filesystem');
        self::assertSame(ChainLoader::class, $definition->getClass());
        self::assertSame('filesystem', $definition->getArgument(0));
        self::assertSame(['uploads', 'assets'], $definition->getArgument(2));
        self::assertSame([['key' => 'filesystem']], $definition->getTag('picasso.loader'));
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>, string}>
     */
    public static function invalidChainProvider(): iterable
    {
        yield 'no loaders' => [
            ['all' => ['type' => 'chain']],
            'Loader "all": a chain loader requires "loaders", the loaders it tries in order (e.g. "loaders: [uploads, assets]").',
        ];
        yield 'unknown member' => [
            ['all' => ['type' => 'chain', 'loaders' => ['nope']]],
            'Loader "all": chain member "nope" must be a filesystem, flysystem, vich or asset_mapper loader declared under "picasso.loaders".',
        ];
        yield 'disabled member' => [
            ['uploads' => ['type' => 'filesystem', 'path' => '/uploads', 'enabled' => false], 'all' => ['type' => 'chain', 'loaders' => ['uploads']]],
            'Loader "all": chain member "uploads" must be a filesystem, flysystem, vich or asset_mapper loader declared under "picasso.loaders".',
        ];
        yield 'member that would fetch remote images to tell whether it holds one' => [
            ['url' => [], 'all' => ['type' => 'chain', 'loaders' => ['url']]],
            'Loader "all": chain member "url" must be a filesystem, flysystem, vich or asset_mapper loader declared under "picasso.loaders".',
        ];
        yield 'nested chain' => [
            ['inner' => ['type' => 'chain', 'loaders' => ['all']], 'all' => ['type' => 'chain', 'loaders' => ['inner']]],
            'Loader "inner": chain member "all" must be a filesystem, flysystem, vich or asset_mapper loader declared under "picasso.loaders".',
        ];
        yield 'loaders on another type' => [
            ['uploads' => ['type' => 'filesystem', 'path' => '/uploads', 'loaders' => ['assets']]],
            'Loader "uploads": the "loaders" option is only supported by chain loaders.',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $loaders
     */
    #[DataProvider('invalidChainProvider')]
    public function testInvalidChainLoaderIsRejected(array $loaders, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->loadExtension(['loaders' => $loaders]);
    }

    public function testUrlLoaderAllowedHostsAreWired(): void
    {
        $container = $this->loadExtension([
            'loaders' => [
                'url' => [],
                'remote' => ['type' => 'url', 'allowed_hosts' => ['Images.Example.com', '*.cdn.example.com']],
            ],
        ]);

        self::assertSame([], $container->getDefinition('picasso.loader.url')->getArgument(2));
        self::assertSame(['images.example.com', '*.cdn.example.com'], $container->getDefinition('picasso.loader.remote')->getArgument(2));
    }

    public function testUrlLoadersAreResetBetweenRequests(): void
    {
        $container = $this->loadExtension([
            'loaders' => [
                'url' => [],
                'local' => ['type' => 'filesystem', 'path' => '%kernel.project_dir%/tests/Fixtures'],
            ],
        ]);

        self::assertSame([['method' => 'reset']], $container->getDefinition('picasso.loader.url')->getTag('kernel.reset'));
        self::assertSame([], $container->getDefinition('picasso.loader.local')->getTag('kernel.reset'));
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>, string}>
     */
    public static function invalidAllowedHostsProvider(): iterable
    {
        yield 'on another type' => [
            ['uploads' => ['type' => 'filesystem', 'path' => '/uploads', 'allowed_hosts' => ['example.com']]],
            'Loader "uploads": the "allowed_hosts" option is only supported by url loaders.',
        ];

        foreach (['https://example.com', 'example.com:8080', '*', '*example.com', 'images.*.example.com', '-example.com', ''] as $host) {
            yield sprintf('"%s"', $host) => [
                ['url' => ['allowed_hosts' => [$host]]],
                sprintf('Loader "url": allowed host "%s" must be a host name (e.g. "images.example.com"), or "*." followed by one for its subdomains (e.g. "*.example.com").', $host),
            ];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $loaders
     */
    #[DataProvider('invalidAllowedHostsProvider')]
    public function testInvalidAllowedHostsAreRejected(array $loaders, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->loadExtension(['loaders' => $loaders]);
    }

    public function testLegacyUrlControllerDecoratesTheImageControllerWithGlide(): void
    {
        $container = $this->loadExtension([
            'cache_control' => ['error_max_age' => 60],
            'loaders' => [
                'uploads' => ['type' => 'filesystem', 'path' => '/uploads'],
                'uploads_again' => ['type' => 'filesystem', 'path' => '/uploads'],
                'assets' => ['type' => 'filesystem', 'path' => '/assets'],
            ],
            'transformers' => [
                'imgix' => ['base_url' => 'https://example.imgix.net'],
                'glide' => ['sign_key' => 'first-glide-key_0123456789abcdef0123456789abcdef'],
                'other_glide' => ['type' => 'glide', 'sign_key' => 'other-key_0123456789abcdef0123456789abcdef'],
            ],
        ]);

        $controller = $container->getDefinition('.picasso.controller.legacy_url');
        self::assertSame(LegacyUrlController::class, $controller->getClass());
        self::assertSame(['picasso.controller.image', null, 0], $controller->getDecoratedService());
        self::assertSame(60, $controller->getArgument(4));

        // 1.x encrypted "_metadata" with the first Glide sign key; the first loader reading a root serves it
        $resolver = $container->getDefinition(LegacyMetadataResolver::SERVICE);
        self::assertSame('first-glide-key_0123456789abcdef0123456789abcdef', $resolver->getArgument(0));
        self::assertSame(['/uploads' => 'uploads', '/assets' => 'assets'], $resolver->getArgument(1));
    }

    public function testImageControllerIsNotDecoratedWithoutGlide(): void
    {
        $container = $this->loadExtension(['transformers' => ['imgix' => ['base_url' => 'https://example.imgix.net']]]);

        self::assertFalse($container->hasDefinition('.picasso.controller.legacy_url'));
        self::assertFalse($container->hasDefinition(LegacyMetadataResolver::SERVICE));
    }

    public function testTransformerWithUnknownTypeThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Transformer "my_custom" must specify a "type"');

        $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'path' => __DIR__ . '/../Fixtures',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
                'images_b' => [
                    'type' => 'filesystem',
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
                'disabled_one' => [
                    'type' => 'filesystem',
                    'enabled' => false,
                    'path' => '/nonexistent',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'sign_key' => 'secret-0123456789abcdef0123456789abcdef',
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
            $container->getDefinition('picasso.controller.image')->getArgument(2),
        );
    }

    public function testGlideCdnOptionsAreOffByDefault(): void
    {
        $container = $this->loadExtension([
            'transformers' => [
                'glide' => ['sign_key' => 'secret-0123456789abcdef0123456789abcdef'],
            ],
        ]);

        $transformer = $container->getDefinition('picasso.transformer.glide');
        self::assertNull($transformer->getArgument(8));
        self::assertSame('', $transformer->getArgument(9));
        self::assertNull($transformer->getArgument(10));
        self::assertNull($transformer->getArgument(11), 'Render locks are opt-in.');
        self::assertFalse($container->hasDefinition('picasso.deferred_cache_writer'));
        self::assertSame(
            ['max_age' => 31536000, 'immutable' => true, 'error_max_age' => null],
            $container->getDefinition('picasso.controller.image')->getArgument(2),
        );
    }

    #[RunInSeparateProcess]
    #[RequiresPhpunit('>=12.0.0')]
    public function testGlideRenderLockRequiresSymfonyLock(): void
    {
        self::hideFromAutoloading(LockFactory::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The "lock" option of transformer "glide" requires symfony/lock: run "composer require symfony/lock", then configure "framework.lock" (or point "lock.factory" to your own LockFactory service).');

        $this->loadExtension(['transformers' => ['glide' => ['sign_key' => self::SIGN_KEY, 'lock' => true]]]);
    }

    #[RunInSeparateProcess]
    #[RequiresPhpunit('>=12.0.0')]
    public function testGlideWithoutRenderLockDoesNotNeedSymfonyLock(): void
    {
        self::hideFromAutoloading(LockFactory::class);

        $container = $this->loadExtension(['transformers' => ['glide' => ['sign_key' => self::SIGN_KEY]]]);

        self::assertNull($container->getDefinition('picasso.transformer.glide')->getArgument(11));
    }

    public function testGlideRenderLockIsWired(): void
    {
        $container = $this->loadExtension([
            'transformers' => [
                'glide' => [
                    'sign_key' => 'secret-0123456789abcdef0123456789abcdef',
                    'lock' => ['enabled' => true, 'factory' => 'app.lock_factory', 'ttl' => 10, 'wait' => 5],
                ],
                'defaults' => [
                    'type' => 'glide',
                    'sign_key' => 'secret-0123456789abcdef0123456789abcdef',
                    'lock' => true,
                ],
            ],
        ]);

        $transformer = $container->getDefinition('picasso.transformer.glide');
        self::assertEquals(new Reference('app.lock_factory'), $transformer->getArgument(11));
        self::assertSame(10.0, $transformer->getArgument(12));
        self::assertSame(5.0, $transformer->getArgument(13));

        $defaults = $container->getDefinition('picasso.transformer.defaults');
        self::assertEquals(new Reference('lock.factory'), $defaults->getArgument(11), 'The LockFactory framework.lock configures.');
        self::assertSame(30.0, $defaults->getArgument(12));
        self::assertSame(10.0, $defaults->getArgument(13));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function missingGlideSignKeyProvider(): iterable
    {
        yield 'no sign_key' => [[]];
        yield 'empty sign_key' => [['sign_key' => '']];
        yield 'empty list' => [['sign_key' => []]];
        yield 'empty key in a list' => [['sign_key' => [self::SIGN_KEY, '']]];
    }

    /**
     * @param array<string, mixed> $glideConfig
     */
    #[DataProvider('missingGlideSignKeyProvider')]
    public function testGlideRequiresASignKey(array $glideConfig): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Transformer "glide" needs a "sign_key": Glide image URLs are signed with it');

        $this->loadExtension(['transformers' => ['glide' => $glideConfig]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidGlideSignKeyProvider(): iterable
    {
        yield 'map of keys' => [['current' => self::SIGN_KEY]];
        yield 'non-string key in a list' => [[self::SIGN_KEY, 42]];
    }

    #[DataProvider('invalidGlideSignKeyProvider')]
    public function testGlideSignKeyMustBeAStringOrAListOfStrings(mixed $signKey): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessage('The "sign_key" option must be a string or a list of strings');

        $this->loadExtension(['transformers' => ['glide' => ['sign_key' => $signKey]]]);
    }

    public function testGlideSignKeysAreWiredInOrder(): void
    {
        $container = $this->loadExtension([
            'loaders' => ['uploads' => ['type' => 'filesystem', 'path' => '/uploads']],
            'transformers' => [
                'glide' => ['sign_key' => ['new-' . self::SIGN_KEY, self::SIGN_KEY, 'short']],
            ],
        ]);

        $keys = ['new-' . self::SIGN_KEY, self::SIGN_KEY, 'short'];
        self::assertSame($keys, $container->getDefinition('picasso.transformer.glide')->getArgument(2));
        // 1.x tokens minted with any of them keep decrypting
        self::assertSame($keys, $container->getDefinition(LegacyMetadataResolver::SERVICE)->getArgument(0));
    }

    public function testImgixSignKeyStaysOptional(): void
    {
        $container = $this->loadExtension(['transformers' => ['imgix' => ['base_url' => 'https://example.imgix.net', 'sign_key' => 'short']]]);

        self::assertSame('short', $container->getDefinition('picasso.transformer.imgix')->getArgument(1));
    }

    public function testImgixSignKeyCannotBeAList(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The "sign_key" of Imgix transformer "imgix" must be a single signing token, not a list.');

        $this->loadExtension(['transformers' => ['imgix' => ['base_url' => 'https://example.imgix.net', 'sign_key' => ['a', 'b']]]]);
    }

    public function testGlideSignKeyFromTheEnvironmentIsCheckedAtRuntime(): void
    {
        $config = [
            'loaders' => ['filesystem' => ['path' => __DIR__ . '/../Fixtures']],
            'transformers' => ['glide' => ['sign_key' => '%env(PICASSO_WIRING_SIGN_KEY)%']],
        ];

        $_ENV['PICASSO_WIRING_SIGN_KEY'] = self::SIGN_KEY;

        try {
            // A key the environment provides is only known at runtime: the container builds with any value
            self::assertInstanceOf(GlideTransformer::class, $this->transformerRegistry($config)->get('glide'));

            $_ENV['PICASSO_WIRING_SIGN_KEY'] = '';
            $registry = $this->transformerRegistry($config);

            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('A Glide "sign_key" is empty');

            $registry->get('glide');
        } finally {
            unset($_ENV['PICASSO_WIRING_SIGN_KEY']);
        }
    }

    public function testGlideSignKeysFromOneCsvEnvironmentVariable(): void
    {
        $config = [
            'loaders' => ['filesystem' => ['path' => __DIR__ . '/../Fixtures']],
            'transformers' => ['glide' => ['sign_key' => '%env(csv:PICASSO_WIRING_SIGN_KEYS)%']],
        ];

        $_ENV['PICASSO_WIRING_SIGN_KEYS'] = 'new-' . self::SIGN_KEY . ',' . self::SIGN_KEY;

        try {
            $transformer = $this->transformerRegistry($config)->get('glide');
            self::assertInstanceOf(GlideTransformer::class, $transformer);

            // Signed with the first key; a URL signed with the second one is still served
            $url = $transformer->url(new Image(path: 'photo.jpg'), new ImageTransformation(width: 10), ['loader' => 'filesystem', 'transformer' => 'glide']);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertSame((new GlideSignature('new-' . self::SIGN_KEY))->generateSignature('photo.jpg', ['w' => '10']), $query['s']);

            $loader = $this->bootKernel($config)->get('picasso.loader.filesystem');
            self::assertInstanceOf(ServableLoaderInterface::class, $loader);
            $response = $transformer->serve($loader, 'photo.jpg', new Request((new GlideSignature(self::SIGN_KEY))->addSignature('photo.jpg', ['w' => '10'])), ['loader' => 'filesystem', 'transformer' => 'glide']);
            self::assertSame(200, $response->getStatusCode());

            $_ENV['PICASSO_WIRING_SIGN_KEYS'] = '';
            $registry = $this->transformerRegistry($config);

            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('The Glide "sign_key" list is empty');

            $registry->get('glide');
        } finally {
            unset($_ENV['PICASSO_WIRING_SIGN_KEYS']);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function transformerRegistry(array $config): TransformerRegistry
    {
        $registry = $this->bootKernel($config)->get('picasso.transformer_registry');
        self::assertInstanceOf(TransformerRegistry::class, $registry);

        return $registry;
    }

    public function testVipsDriverWithoutItsPackageSaysHowToInstallIt(): void
    {
        if (class_exists('Intervention\\Image\\Drivers\\Vips\\Driver')) {
            self::markTestSkipped('intervention/image-driver-vips is installed.');
        }

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('composer require intervention/image-driver-vips');

        $this->loadExtension([
            'transformers' => [
                'glide' => ['sign_key' => 'secret-0123456789abcdef0123456789abcdef', 'driver' => 'vips'],
            ],
        ]);
    }

    public function testImgixTransformerIsRegistered(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'path' => __DIR__ . '/../Fixtures',
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
        $loader = $container->get('picasso.loader.vich');
        self::assertInstanceOf(VichUploaderLoader::class, $loader);

        // The only mapping is picked, and its upload destination names a Flysystem storage
        $source = $loader->getSource();
        self::assertInstanceOf(FlysystemImageSource::class, $source);
        self::assertTrue($source->exists('photo.jpg'));
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
                    'path' => __DIR__ . '/../Fixtures',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                    'default_placeholder' => 'blur',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                    'default_transformer' => 'glide',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                    'resolve_metadata' => false,
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'disabled_glide' => [
                    'type' => 'glide',
                    'enabled' => false,
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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

    // --- Private loaders ---

    public function testPrivateLoadersAreKnownToTheRegistryAndImageServerIsRegistered(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'documents' => [
                    'type' => 'filesystem',
                    'path' => __DIR__ . '/../Fixtures',
                    'private' => true,
                ],
                'public' => [
                    'type' => 'filesystem',
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
                ],
            ],
        ]);

        $loaders = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $loaders);
        self::assertTrue($loaders->isPrivate('documents'));
        self::assertFalse($loaders->isPrivate('public'));
        self::assertInstanceOf(ImageServer::class, $container->get('picasso.image_server'));
    }

    // --- Attribute autoconfiguration ---

    public function testAttributeTaggedServicesAreRegisteredWithTheirLoaderDefaults(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
                ],
            ],
        ], withServices: [StubConfiguredAttributeLoader::class, StubAttributeTransformer::class, StubAttributePlaceholder::class]);

        $loaders = $container->get('picasso.loader_registry');
        self::assertInstanceOf(LoaderRegistry::class, $loaders);
        self::assertInstanceOf(StubConfiguredAttributeLoader::class, $loaders->get('configured'));
        self::assertSame('stub', $loaders->getDefaultPlaceholder('configured'));
        self::assertSame('stub', $loaders->getDefaultTransformer('configured'));
        self::assertFalse($loaders->getResolveMetadata('configured'));
        self::assertTrue($loaders->isPrivate('configured'));
        self::assertFalse($loaders->isPrivate('filesystem'));

        $transformers = $container->get('picasso.transformer_registry');
        self::assertInstanceOf(TransformerRegistry::class, $transformers);
        self::assertInstanceOf(StubAttributeTransformer::class, $transformers->get('stub'));

        $placeholders = $container->get('picasso.placeholder_registry');
        self::assertInstanceOf(PlaceholderRegistry::class, $placeholders);
        self::assertInstanceOf(StubAttributePlaceholder::class, $placeholders->get('stub'));
    }

    public function testUrlAliasesComeFromTheConfigAndTheAttributes(): void
    {
        $container = $this->bootKernel([
            'loaders' => [
                'filesystem' => [
                    'path' => __DIR__ . '/../Fixtures',
                    'url_alias' => 'f',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
                    'url_alias' => 'g',
                ],
            ],
        ], withServices: [StubConfiguredAttributeLoader::class, StubAttributeTransformer::class]);

        $aliases = $container->get('picasso.url_aliases');
        self::assertInstanceOf(UrlAliases::class, $aliases);
        self::assertSame('f', $aliases->loaderSegment('filesystem'));
        self::assertSame('cfg', $aliases->loaderSegment('configured'));
        self::assertSame('g', $aliases->transformerSegment('glide'));
        self::assertSame('s', $aliases->transformerSegment('stub'));
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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
                    'path' => __DIR__ . '/../Fixtures',
                ],
            ],
            'transformers' => [
                'glide' => [
                    'sign_key' => 'test-0123456789abcdef0123456789abcdef',
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

    #[RunInSeparateProcess]
    #[RequiresPhpunit('>=12.0.0')]
    public function testBlurhashPlaceholderRequiresImagine(): void
    {
        self::hideFromAutoloading(\Imagine\Image\ImagineInterface::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Placeholder "hash" of type "blurhash" requires the "imagine/imagine" package. Install it with: composer require imagine/imagine');

        $this->loadExtension(['placeholders' => ['hash' => ['type' => 'blurhash']]]);
    }

    #[RunInSeparateProcess]
    #[RequiresPhpunit('>=12.0.0')]
    public function testAssetMapperLoaderRequiresSymfonyAssetMapper(): void
    {
        self::hideFromAutoloading(AssetMapperInterface::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "assets": an asset_mapper loader requires symfony/asset-mapper. Run "composer require symfony/asset-mapper".');

        $this->loadExtension(['loaders' => ['assets' => ['type' => 'asset_mapper']]]);
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
            'kernel.bundles' => [],
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
                'property_access' => true, // see AbstractPicassoKernel
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
                        [__DIR__ . '/../Fixtures'],
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
        return dirname(__DIR__, 2) . '/var/test/wiring/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 2) . '/var/test/wiring/log';
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
        $fixturesDir = __DIR__ . '/../Fixtures';

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
                'property_access' => true, // see AbstractPicassoKernel
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
                        'sign_key' => 'test-key_0123456789abcdef0123456789abcdef',
                        'cache' => '%kernel.cache_dir%/glide',
                    ],
                ],
            ]);

            // VichUploaderBundle needs the slugger, which FrameworkBundle only registers when symfony/string is a
            // non-dev package; here it only comes with require-dev.
            $container->register('slugger', AsciiSlugger::class);
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
        return dirname(__DIR__, 2) . '/var/test/vich/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 2) . '/var/test/vich/log';
    }
}
