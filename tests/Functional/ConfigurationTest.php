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

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\PicassoBundle;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\FileLocator;

/**
 * @phpstan-type PlaceholderConfig array{enabled: bool, type: string|null, size: int, blur: int, quality: int, components_x: int, components_y: int, service: string|null}
 * @phpstan-type LoaderConfig array{enabled: bool, type: string|null, paths: list<string>, storage: string|null, http_client: string|null}
 * @phpstan-type PublicCacheConfig array{enabled: bool}
 * @phpstan-type TransformerConfig array{enabled: bool, type: string|null, sign_key: string|null, cache: string|null, driver: string, max_image_size: int|null, base_url: string|null, service: string|null, public_cache: PublicCacheConfig}
 * @phpstan-type PicassoConfig array{
 *     default_loader: string|null,
 *     default_transformer: string|null,
 *     default_placeholder: string|null,
 *     device_sizes: list<int>,
 *     image_sizes: list<int>,
 *     formats: list<string>,
 *     default_quality: int,
 *     default_fit: string,
 *     placeholders: array<string, PlaceholderConfig>,
 *     loaders: array<string, LoaderConfig>,
 *     transformers: array<string, TransformerConfig>,
 * }
 */
class ConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return PicassoConfig
     */
    private function processConfig(array $config): array
    {
        $bundle = new PicassoBundle();

        $treeBuilder = new TreeBuilder('picasso');
        $locator = new FileLocator(__DIR__);
        $fileLoader = new DefinitionFileLoader($treeBuilder, $locator);
        $configurator = new DefinitionConfigurator($treeBuilder, $fileLoader, __DIR__, __FILE__);
        $bundle->configure($configurator);

        $tree = $treeBuilder->buildTree();

        /** @var PicassoConfig $config */
        $config = $tree->finalize($tree->normalize($config));

        return $config;
    }

    // --- Default values ---

    public function testDefaultConfiguration(): void
    {
        $config = $this->processConfig([]);

        self::assertNull($config['default_loader']);
        self::assertNull($config['default_transformer']);
        self::assertNull($config['default_placeholder']);
        self::assertSame([640, 750, 828, 1080, 1200, 1920, 2048, 3840], $config['device_sizes']);
        self::assertSame([16, 32, 48, 64, 96, 128, 256, 384], $config['image_sizes']);
        self::assertSame(['avif', 'webp', 'jpg'], $config['formats']);
        self::assertSame(75, $config['default_quality']);
        self::assertSame('contain', $config['default_fit']);
        self::assertSame([], $config['placeholders']);
        self::assertSame([], $config['loaders']);
        self::assertSame([], $config['transformers']);
    }

    // --- Formats validation ---

    public function testValidFormats(): void
    {
        $config = $this->processConfig([
            'formats' => ['avif', 'webp', 'jpg', 'jpeg', 'pjpg', 'png', 'gif'],
        ]);

        self::assertSame(['avif', 'webp', 'jpg', 'jpeg', 'pjpg', 'png', 'gif'], $config['formats']);
    }

    public function testInvalidFormatThrowsException(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Invalid format.*bmp/');

        $this->processConfig([
            'formats' => ['bmp'],
        ]);
    }

    public function testSingleFormat(): void
    {
        $config = $this->processConfig([
            'formats' => ['webp'],
        ]);

        self::assertSame(['webp'], $config['formats']);
    }

    // --- Quality validation ---

    public function testQualityMinBound(): void
    {
        $config = $this->processConfig([
            'default_quality' => 1,
        ]);

        self::assertSame(1, $config['default_quality']);
    }

    public function testQualityMaxBound(): void
    {
        $config = $this->processConfig([
            'default_quality' => 100,
        ]);

        self::assertSame(100, $config['default_quality']);
    }

    public function testQualityBelowMinThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([
            'default_quality' => 0,
        ]);
    }

    public function testQualityAboveMaxThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([
            'default_quality' => 101,
        ]);
    }

    // --- Placeholder quality validation ---

    public function testPlaceholderQualityMinBound(): void
    {
        $config = $this->processConfig([
            'placeholders' => ['blur' => ['type' => 'transformer', 'quality' => 1]],
        ]);

        self::assertSame(1, $config['placeholders']['blur']['quality']);
    }

    public function testPlaceholderQualityMaxBound(): void
    {
        $config = $this->processConfig([
            'placeholders' => ['blur' => ['type' => 'transformer', 'quality' => 100]],
        ]);

        self::assertSame(100, $config['placeholders']['blur']['quality']);
    }

    public function testPlaceholderQualityBelowMinThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([
            'placeholders' => ['blur' => ['type' => 'transformer', 'quality' => 0]],
        ]);
    }

    public function testPlaceholderQualityAboveMaxThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([
            'placeholders' => ['blur' => ['type' => 'transformer', 'quality' => 101]],
        ]);
    }

    // --- Placeholder settings ---

    public function testTransformerPlaceholderConfig(): void
    {
        $config = $this->processConfig([
            'placeholders' => ['blur' => [
                'type' => 'transformer',
                'size' => 20,
                'blur' => 10,
                'quality' => 50,
            ]],
        ]);

        self::assertSame('transformer', $config['placeholders']['blur']['type']);
        self::assertSame(20, $config['placeholders']['blur']['size']);
        self::assertSame(10, $config['placeholders']['blur']['blur']);
        self::assertSame(50, $config['placeholders']['blur']['quality']);
        self::assertTrue($config['placeholders']['blur']['enabled']);
    }

    public function testServicePlaceholderConfig(): void
    {
        $config = $this->processConfig([
            'placeholders' => ['custom' => [
                'type' => 'service',
                'service' => 'app.my_placeholder',
            ]],
        ]);

        self::assertSame('service', $config['placeholders']['custom']['type']);
        self::assertSame('app.my_placeholder', $config['placeholders']['custom']['service']);
    }

    public function testBlurHashPlaceholderConfig(): void
    {
        $config = $this->processConfig([
            'placeholders' => ['hash' => [
                'type' => 'blurhash',
                'components_x' => 5,
                'components_y' => 4,
                'size' => 16,
            ]],
        ]);

        self::assertSame('blurhash', $config['placeholders']['hash']['type']);
        self::assertSame(5, $config['placeholders']['hash']['components_x']);
        self::assertSame(4, $config['placeholders']['hash']['components_y']);
        self::assertSame(16, $config['placeholders']['hash']['size']);
        self::assertTrue($config['placeholders']['hash']['enabled']);
    }

    public function testBlurHashPlaceholderDefaults(): void
    {
        $config = $this->processConfig([
            'placeholders' => ['blurhash' => [
                'type' => 'blurhash',
            ]],
        ]);

        self::assertSame(4, $config['placeholders']['blurhash']['components_x']);
        self::assertSame(3, $config['placeholders']['blurhash']['components_y']);
        self::assertSame(10, $config['placeholders']['blurhash']['size']);
    }

    public function testBlurHashComponentsXBoundsThrow(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([
            'placeholders' => ['hash' => [
                'type' => 'blurhash',
                'components_x' => 10,
            ]],
        ]);
    }

    public function testBlurHashComponentsYBoundsThrow(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([
            'placeholders' => ['hash' => [
                'type' => 'blurhash',
                'components_y' => 0,
            ]],
        ]);
    }

    public function testBlurHashTypeInferredFromName(): void
    {
        $config = $this->processConfig([
            'placeholders' => ['blurhash' => []],
        ]);

        self::assertNull($config['placeholders']['blurhash']['type']);
    }

    public function testDisabledPlaceholderConfig(): void
    {
        $config = $this->processConfig([
            'placeholders' => ['blur' => [
                'type' => 'transformer',
                'enabled' => false,
            ]],
        ]);

        self::assertFalse($config['placeholders']['blur']['enabled']);
    }

    public function testDefaultPlaceholderConfig(): void
    {
        $config = $this->processConfig([
            'default_placeholder' => 'blur',
        ]);

        self::assertSame('blur', $config['default_placeholder']);
    }

    public function testPlaceholderTypeInferredFromName(): void
    {
        $config = $this->processConfig([
            'placeholders' => ['transformer' => []],
        ]);

        // Type is null in config tree (inferred at loadExtension time)
        self::assertNull($config['placeholders']['transformer']['type']);
    }

    public function testMultiplePlaceholders(): void
    {
        $config = $this->processConfig([
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                    'size' => 15,
                ],
                'custom' => [
                    'type' => 'service',
                    'service' => 'app.blurhash',
                ],
                'disabled_one' => [
                    'type' => 'transformer',
                    'enabled' => false,
                ],
            ],
        ]);

        self::assertCount(3, $config['placeholders']);
        self::assertTrue($config['placeholders']['blur']['enabled']);
        self::assertTrue($config['placeholders']['custom']['enabled']);
        self::assertFalse($config['placeholders']['disabled_one']['enabled']);
    }

    // --- Fit values ---

    public function testDefaultFitContain(): void
    {
        $config = $this->processConfig([]);
        self::assertSame('contain', $config['default_fit']);
    }

    public function testCustomFitCover(): void
    {
        $config = $this->processConfig(['default_fit' => 'cover']);
        self::assertSame('cover', $config['default_fit']);
    }

    public function testCustomFitCrop(): void
    {
        $config = $this->processConfig(['default_fit' => 'crop']);
        self::assertSame('crop', $config['default_fit']);
    }

    public function testCustomFitFill(): void
    {
        $config = $this->processConfig(['default_fit' => 'fill']);
        self::assertSame('fill', $config['default_fit']);
    }

    // --- Device / Image sizes ---

    public function testCustomDeviceSizes(): void
    {
        $config = $this->processConfig([
            'device_sizes' => [320, 640, 1280],
        ]);

        self::assertSame([320, 640, 1280], $config['device_sizes']);
    }

    public function testCustomImageSizes(): void
    {
        $config = $this->processConfig([
            'image_sizes' => [24, 48, 96],
        ]);

        self::assertSame([24, 48, 96], $config['image_sizes']);
    }

    public function testEmptyDeviceSizes(): void
    {
        $config = $this->processConfig([
            'device_sizes' => [],
        ]);

        self::assertSame([], $config['device_sizes']);
    }

    public function testEmptyImageSizes(): void
    {
        $config = $this->processConfig([
            'image_sizes' => [],
        ]);

        self::assertSame([], $config['image_sizes']);
    }

    // --- Loader configuration ---

    public function testFilesystemLoaderConfig(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'my_fs' => [
                    'type' => 'filesystem',
                    'paths' => ['/var/images', '/opt/assets'],
                ],
            ],
        ]);

        self::assertSame('filesystem', $config['loaders']['my_fs']['type']);
        self::assertSame(['/var/images', '/opt/assets'], $config['loaders']['my_fs']['paths']);
        self::assertTrue($config['loaders']['my_fs']['enabled']);
    }

    public function testFlysystemLoaderConfig(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'my_fly' => [
                    'type' => 'flysystem',
                    'storage' => 'my.flysystem.storage',
                ],
            ],
        ]);

        self::assertSame('flysystem', $config['loaders']['my_fly']['type']);
        self::assertSame('my.flysystem.storage', $config['loaders']['my_fly']['storage']);
    }

    public function testFlysystemLoaderWithoutStorageThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/flysystem loader requires a "storage"/');

        $this->processConfig([
            'loaders' => [
                'my_fly' => [
                    'type' => 'flysystem',
                ],
            ],
        ]);
    }

    public function testFlysystemLoaderWithEmptyStorageThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/flysystem loader requires a "storage"/');

        $this->processConfig([
            'loaders' => [
                'my_fly' => [
                    'type' => 'flysystem',
                    'storage' => '',
                ],
            ],
        ]);
    }

    public function testFilesystemLoaderWithStorageThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/storage.*not supported for filesystem/');

        $this->processConfig([
            'loaders' => [
                'my_fs' => [
                    'type' => 'filesystem',
                    'storage' => 'some.service',
                ],
            ],
        ]);
    }

    public function testVichLoaderConfig(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'my_vich' => [
                    'type' => 'vich',
                ],
            ],
        ]);

        self::assertSame('vich', $config['loaders']['my_vich']['type']);
        self::assertTrue($config['loaders']['my_vich']['enabled']);
    }

    public function testMultipleVichLoaders(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'vich_products' => [
                    'type' => 'vich',
                ],
                'vich_avatars' => [
                    'type' => 'vich',
                ],
            ],
        ]);

        self::assertCount(2, $config['loaders']);
        self::assertSame('vich', $config['loaders']['vich_products']['type']);
        self::assertSame('vich', $config['loaders']['vich_avatars']['type']);
    }

    public function testUrlLoaderConfig(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'remote' => [
                    'type' => 'url',
                ],
            ],
        ]);

        self::assertSame('url', $config['loaders']['remote']['type']);
        self::assertNull($config['loaders']['remote']['http_client']);
    }

    public function testUrlLoaderWithCustomHttpClient(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'remote' => [
                    'type' => 'url',
                    'http_client' => 'my.custom_http_client',
                ],
            ],
        ]);

        self::assertSame('my.custom_http_client', $config['loaders']['remote']['http_client']);
    }

    public function testLoaderTypeInferredFromName(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'filesystem' => [
                    'paths' => ['/var/images'],
                ],
            ],
        ]);

        // Type is null in config tree (inferred at loadExtension time)
        self::assertNull($config['loaders']['filesystem']['type']);
    }

    public function testDisabledLoaderConfig(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'unused' => [
                    'type' => 'filesystem',
                    'enabled' => false,
                    'paths' => ['/nonexistent'],
                ],
            ],
        ]);

        self::assertFalse($config['loaders']['unused']['enabled']);
    }

    public function testMultipleLoadersOfSameType(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'images_a' => [
                    'type' => 'filesystem',
                    'paths' => ['/var/images-a'],
                ],
                'images_b' => [
                    'type' => 'filesystem',
                    'paths' => ['/var/images-b'],
                ],
                'images_c' => [
                    'type' => 'filesystem',
                    'paths' => ['/var/images-c'],
                ],
            ],
        ]);

        self::assertCount(3, $config['loaders']);
        self::assertSame(['/var/images-a'], $config['loaders']['images_a']['paths']);
        self::assertSame(['/var/images-b'], $config['loaders']['images_b']['paths']);
        self::assertSame(['/var/images-c'], $config['loaders']['images_c']['paths']);
    }

    public function testMultiplePathsForFilesystemLoader(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'multi_path' => [
                    'type' => 'filesystem',
                    'paths' => ['/var/uploads', '/var/assets', '/opt/media'],
                ],
            ],
        ]);

        self::assertCount(3, $config['loaders']['multi_path']['paths']);
    }

    // --- Transformer configuration ---

    public function testGlideTransformerConfig(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'my_glide' => [
                    'type' => 'glide',
                    'sign_key' => 'secret-key',
                    'cache' => '/var/cache/glide',
                    'driver' => 'gd',
                ],
            ],
        ]);

        self::assertSame('glide', $config['transformers']['my_glide']['type']);
        self::assertSame('secret-key', $config['transformers']['my_glide']['sign_key']);
        self::assertSame('/var/cache/glide', $config['transformers']['my_glide']['cache']);
        self::assertSame('gd', $config['transformers']['my_glide']['driver']);
        self::assertNull($config['transformers']['my_glide']['max_image_size']);
        self::assertFalse($config['transformers']['my_glide']['public_cache']['enabled']);
    }

    public function testGlideTransformerPublicCacheConfig(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'my_glide' => [
                    'type' => 'glide',
                    'sign_key' => 'secret-key',
                    'public_cache' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ]);

        self::assertTrue($config['transformers']['my_glide']['public_cache']['enabled']);
    }

    public function testGlideTransformerWithImagickDriver(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'my_glide' => [
                    'type' => 'glide',
                    'driver' => 'imagick',
                ],
            ],
        ]);

        self::assertSame('imagick', $config['transformers']['my_glide']['driver']);
    }

    public function testGlideTransformerInvalidDriverThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Driver must be "gd" or "imagick"/');

        $this->processConfig([
            'transformers' => [
                'my_glide' => [
                    'type' => 'glide',
                    'driver' => 'vips',
                ],
            ],
        ]);
    }

    public function testGlideTransformerWithMaxImageSize(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'my_glide' => [
                    'type' => 'glide',
                    'max_image_size' => 2048 * 2048,
                ],
            ],
        ]);

        self::assertSame(2048 * 2048, $config['transformers']['my_glide']['max_image_size']);
    }

    public function testImgixTransformerConfig(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'my_imgix' => [
                    'type' => 'imgix',
                    'base_url' => 'https://my-source.imgix.net',
                    'sign_key' => 'imgix-secret',
                ],
            ],
        ]);

        self::assertSame('imgix', $config['transformers']['my_imgix']['type']);
        self::assertSame('https://my-source.imgix.net', $config['transformers']['my_imgix']['base_url']);
        self::assertSame('imgix-secret', $config['transformers']['my_imgix']['sign_key']);
    }

    public function testImgixTransformerWithoutSignKey(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'my_imgix' => [
                    'type' => 'imgix',
                    'base_url' => 'https://my-source.imgix.net',
                ],
            ],
        ]);

        self::assertNull($config['transformers']['my_imgix']['sign_key']);
    }

    public function testServiceTransformerConfig(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'custom' => [
                    'type' => 'service',
                    'service' => 'app.my_custom_transformer',
                ],
            ],
        ]);

        self::assertSame('service', $config['transformers']['custom']['type']);
        self::assertSame('app.my_custom_transformer', $config['transformers']['custom']['service']);
    }

    public function testTransformerTypeInferredFromName(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'glide' => [
                    'sign_key' => 'key',
                ],
                'imgix' => [
                    'base_url' => 'https://example.imgix.net',
                ],
            ],
        ]);

        // Type is null in config tree (inferred at loadExtension time)
        self::assertNull($config['transformers']['glide']['type']);
        self::assertNull($config['transformers']['imgix']['type']);
    }

    public function testDisabledTransformerConfig(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'unused' => [
                    'type' => 'glide',
                    'enabled' => false,
                ],
            ],
        ]);

        self::assertFalse($config['transformers']['unused']['enabled']);
    }

    public function testMultipleTransformers(): void
    {
        $config = $this->processConfig([
            'transformers' => [
                'local' => [
                    'type' => 'glide',
                    'sign_key' => 'local-key',
                ],
                'cdn' => [
                    'type' => 'imgix',
                    'base_url' => 'https://cdn.imgix.net',
                ],
                'custom_service' => [
                    'type' => 'service',
                    'service' => 'app.transformer',
                ],
                'disabled_one' => [
                    'type' => 'glide',
                    'enabled' => false,
                ],
            ],
        ]);

        self::assertCount(4, $config['transformers']);
        self::assertTrue($config['transformers']['local']['enabled']);
        self::assertTrue($config['transformers']['cdn']['enabled']);
        self::assertTrue($config['transformers']['custom_service']['enabled']);
        self::assertFalse($config['transformers']['disabled_one']['enabled']);
    }

    // --- Mixed loaders of different types ---

    public function testMixedLoaderTypes(): void
    {
        $config = $this->processConfig([
            'loaders' => [
                'local_fs' => [
                    'type' => 'filesystem',
                    'paths' => ['/var/images'],
                ],
                'cloud' => [
                    'type' => 'flysystem',
                    'storage' => 'flysystem.s3_storage',
                ],
                'vich_uploads' => [
                    'type' => 'vich',
                ],
                'remote_api' => [
                    'type' => 'url',
                    'http_client' => 'app.image_http_client',
                ],
            ],
        ]);

        self::assertCount(4, $config['loaders']);
        self::assertSame('filesystem', $config['loaders']['local_fs']['type']);
        self::assertSame('flysystem', $config['loaders']['cloud']['type']);
        self::assertSame('vich', $config['loaders']['vich_uploads']['type']);
        self::assertSame('url', $config['loaders']['remote_api']['type']);
    }

    // --- Explicit default_loader / default_transformer ---

    public function testExplicitDefaultLoader(): void
    {
        $config = $this->processConfig([
            'default_loader' => 'my_custom_loader',
        ]);

        self::assertSame('my_custom_loader', $config['default_loader']);
    }

    public function testExplicitDefaultTransformer(): void
    {
        $config = $this->processConfig([
            'default_transformer' => 'my_custom_transformer',
        ]);

        self::assertSame('my_custom_transformer', $config['default_transformer']);
    }

    // --- Full realistic configuration ---

    public function testFullRealisticConfiguration(): void
    {
        $config = $this->processConfig([
            'default_loader' => 'uploads',
            'default_transformer' => 'cdn',
            'default_placeholder' => 'blur',
            'device_sizes' => [320, 640, 1024, 1440],
            'image_sizes' => [32, 64, 128],
            'formats' => ['avif', 'webp', 'png'],
            'default_quality' => 85,
            'default_fit' => 'cover',
            'placeholders' => [
                'blur' => [
                    'type' => 'transformer',
                    'size' => 15,
                    'blur' => 8,
                    'quality' => 20,
                ],
            ],
            'loaders' => [
                'uploads' => [
                    'type' => 'filesystem',
                    'paths' => ['/var/www/uploads', '/var/www/assets'],
                ],
                'cloud_storage' => [
                    'type' => 'flysystem',
                    'storage' => 'flysystem.default_storage',
                ],
                'vich_products' => [
                    'type' => 'vich',
                ],
                'vich_avatars' => [
                    'type' => 'vich',
                ],
                'external' => [
                    'type' => 'url',
                    'http_client' => 'app.image_client',
                ],
                'legacy' => [
                    'type' => 'filesystem',
                    'enabled' => false,
                    'paths' => ['/old/images'],
                ],
            ],
            'transformers' => [
                'local' => [
                    'type' => 'glide',
                    'sign_key' => 'my-secret',
                    'cache' => '/tmp/glide',
                    'driver' => 'imagick',
                    'max_image_size' => 4194304,
                ],
                'cdn' => [
                    'type' => 'imgix',
                    'base_url' => 'https://myapp.imgix.net',
                    'sign_key' => 'imgix-token',
                ],
                'custom' => [
                    'type' => 'service',
                    'service' => 'app.cloudinary_transformer',
                ],
            ],
        ]);

        self::assertSame('uploads', $config['default_loader']);
        self::assertSame('cdn', $config['default_transformer']);
        self::assertSame('blur', $config['default_placeholder']);
        self::assertSame([320, 640, 1024, 1440], $config['device_sizes']);
        self::assertSame([32, 64, 128], $config['image_sizes']);
        self::assertSame(['avif', 'webp', 'png'], $config['formats']);
        self::assertSame(85, $config['default_quality']);
        self::assertSame('cover', $config['default_fit']);
        self::assertSame(15, $config['placeholders']['blur']['size']);
        self::assertSame(8, $config['placeholders']['blur']['blur']);
        self::assertSame(20, $config['placeholders']['blur']['quality']);
        self::assertCount(6, $config['loaders']);
        self::assertCount(3, $config['transformers']);
        self::assertFalse($config['loaders']['legacy']['enabled']);
        self::assertSame(4194304, $config['transformers']['local']['max_image_size']);
    }
}
