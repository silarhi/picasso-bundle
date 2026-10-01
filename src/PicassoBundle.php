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

namespace Silarhi\PicassoBundle;

use function assert;
use function count;
use function dirname;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;

use Override;
use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Attribute\AsImageTransformer;
use Silarhi\PicassoBundle\Attribute\AsPlaceholder;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Controller\LegacyUrlController;
use Silarhi\PicassoBundle\DataCollector\CollectingImageHelper;
use Silarhi\PicassoBundle\DataCollector\CollectingMetadataGuesser;
use Silarhi\PicassoBundle\DataCollector\PicassoDataCollector;
use Silarhi\PicassoBundle\DependencyInjection\VichLoaderPass;
use Silarhi\PicassoBundle\Loader\ChainLoader;
use Silarhi\PicassoBundle\Loader\FilesystemLoader;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\UrlLoader;
use Silarhi\PicassoBundle\Loader\VichMappingHelper;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\Placeholder\BlurHashPlaceholder;
use Silarhi\PicassoBundle\Placeholder\PlaceholderInterface;
use Silarhi\PicassoBundle\Placeholder\TransformerPlaceholder;
use Silarhi\PicassoBundle\Service\ImageHelper;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\LegacyMetadataResolver;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\MetadataGuesser;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Silarhi\PicassoBundle\Service\PlaceholderRegistry;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\DeferredCacheWriter;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\ImgixTransformer;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;

use function sprintf;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Vich\UploaderBundle\Storage\StorageInterface as VichStorageInterface;

final class PicassoBundle extends AbstractBundle
{
    private const ALLOWED_FORMATS = ['avif', 'webp', 'jpg', 'jpeg', 'pjpg', 'png', 'gif'];

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerAttributeForAutoconfiguration(
            AsImageLoader::class,
            static function (ChildDefinition $definition, AsImageLoader $attribute): void {
                $tag = ['key' => $attribute->name];
                if (null !== $attribute->defaultPlaceholder) {
                    $tag['default_placeholder'] = $attribute->defaultPlaceholder;
                }
                if (null !== $attribute->defaultTransformer) {
                    $tag['default_transformer'] = $attribute->defaultTransformer;
                }
                if (null !== $attribute->resolveMetadata) {
                    $tag['resolve_metadata'] = $attribute->resolveMetadata;
                }
                $definition->addTag('picasso.loader', $tag);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsImageTransformer::class,
            static function (ChildDefinition $definition, AsImageTransformer $attribute): void {
                $definition->addTag('picasso.transformer', ['key' => $attribute->name]);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsPlaceholder::class,
            static function (ChildDefinition $definition, AsPlaceholder $attribute): void {
                $definition->addTag('picasso.placeholder', ['key' => $attribute->name]);
            },
        );

        $container->addCompilerPass(new VichLoaderPass());

        // Merge per-loader defaults from attribute-tagged loaders into LoaderRegistry
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if (!$container->hasDefinition('picasso.loader_registry')) {
                    return;
                }

                $definition = $container->getDefinition('picasso.loader_registry');
                /** @var array<string, string> $placeholders */
                $placeholders = $definition->getArgument(1);
                /** @var array<string, string> $transformers */
                $transformers = $definition->getArgument(2);
                /** @var array<string, bool> $resolveMetadataMap */
                $resolveMetadataMap = $definition->getArgument(3);

                foreach ($container->findTaggedServiceIds('picasso.loader') as $tags) {
                    /** @var array{key?: string, default_placeholder?: string, default_transformer?: string, resolve_metadata?: bool} $tag */
                    foreach ($tags as $tag) {
                        if (isset($tag['key'], $tag['default_placeholder'])) {
                            $placeholders[$tag['key']] ??= $tag['default_placeholder'];
                        }
                        if (isset($tag['key'], $tag['default_transformer'])) {
                            $transformers[$tag['key']] ??= $tag['default_transformer'];
                        }
                        if (isset($tag['key'], $tag['resolve_metadata'])) {
                            $resolveMetadataMap[$tag['key']] ??= $tag['resolve_metadata'];
                        }
                    }
                }

                $definition->replaceArgument(1, $placeholders);
                $definition->replaceArgument(2, $transformers);
                $definition->replaceArgument(3, $resolveMetadataMap);
            }
        });
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $allowedFormats = self::ALLOWED_FORMATS;

        $definition->rootNode()
            ->children()
                ->scalarNode('default_loader')
                    ->defaultNull()
                    ->info('Default loader name.')
                ->end()
                ->scalarNode('default_transformer')
                    ->defaultNull()
                    ->info('Default transformer name. Auto-detected when only one is configured.')
                ->end()
                ->arrayNode('device_sizes')
                    ->defaultValue([640, 750, 828, 1080, 1200, 1920, 2048, 3840])
                    ->integerPrototype()->end()
                ->end()
                ->arrayNode('image_sizes')
                    ->defaultValue([16, 32, 48, 64, 96, 128, 256, 384])
                    ->integerPrototype()->end()
                ->end()
                ->arrayNode('formats')
                    ->defaultValue(['avif', 'webp', 'jpg'])
                    ->scalarPrototype()
                        ->validate()
                            ->ifNotInArray($allowedFormats)
                            ->thenInvalid('Invalid format "%s". Allowed: ' . implode(', ', $allowedFormats))
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('default_quality')
                    ->defaultValue(75)
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => null !== $v && (!is_int($v) || $v < 1 || $v > 100))
                        ->thenInvalid('The "default_quality" must be null or an integer between 1 and 100.')
                    ->end()
                ->end()
                ->scalarNode('default_fit')
                    ->defaultValue('contain')
                    ->info('Default fit mode (contain, cover, crop, fill).')
                ->end()
                ->scalarNode('cache')
                    ->defaultTrue()
                    ->info('PSR-6 cache pool for metadata guessing and BlurHash generation. true (default) uses cache.app, false disables caching, or pass a service ID string.')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_bool($v) && !is_string($v))
                        ->thenInvalid('The "cache" option must be true, false, or a cache pool service ID string.')
                    ->end()
                ->end()
                ->arrayNode('cache_control')
                    ->info('HTTP cache headers of the images served by the bundle controller (local transformers such as Glide).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('max_age')
                            ->defaultValue(31536000)
                            ->info('Seconds clients and CDNs may cache a served image. Null keeps the headers set by the transformer.')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => null !== $v && (!is_int($v) || $v < 0))
                                ->thenInvalid('The "max_age" option must be null or a non-negative integer.')
                            ->end()
                        ->end()
                        ->booleanNode('immutable')
                            ->defaultTrue()
                            ->info('Mark served images immutable: a variant URL never changes meaning, so caches need not revalidate it.')
                        ->end()
                        ->scalarNode('error_max_age')
                            ->defaultNull()
                            ->info('Seconds clients and CDNs may cache a 404. Null keeps 404s uncacheable.')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => null !== $v && (!is_int($v) || $v < 0))
                                ->thenInvalid('The "error_max_age" option must be null or a non-negative integer.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('resolve_metadata')
                    ->defaultFalse()
                    ->info('Whether to resolve image metadata (dimensions) from the source by default. Filesystem loaders default to true.')
                ->end()
                ->booleanNode('collector')
                    ->defaultFalse()
                    ->info('Enable the web profiler data collector for Picasso. Disabled by default; turn on in dev to debug image rendering.')
                ->end()
                ->scalarNode('default_placeholder')
                    ->defaultNull()
                    ->info('Default placeholder name. Auto-detected when only one is configured.')
                ->end()
                ->arrayNode('placeholders')
                    ->useAttributeAsKey('name')
                    ->defaultValue([])
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->enumNode('type')
                                ->values(['transformer', 'blurhash', 'service'])
                                ->defaultNull()
                                ->info('Placeholder type. Inferred from name when it matches a known type.')
                            ->end()
                            ->integerNode('size')->defaultValue(10)->info('Tiny image size for transformer placeholders.')->end()
                            ->scalarNode('blur')
                                ->defaultValue(5)
                                ->info('Blur amount for transformer placeholders. Null disables blur.')
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => null !== $v && !is_int($v))
                                    ->thenInvalid('The "blur" option must be null or an integer.')
                                ->end()
                            ->end()
                            ->scalarNode('quality')
                                ->defaultValue(30)
                                ->info('Quality for transformer placeholders. Null uses transformer default.')
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => null !== $v && (!is_int($v) || $v < 1 || $v > 100))
                                    ->thenInvalid('The "quality" option must be null or an integer between 1 and 100.')
                                ->end()
                            ->end()
                            ->scalarNode('fit')
                                ->defaultValue('crop')
                                ->info('Fit mode for transformer placeholders. Null uses transformer default.')
                            ->end()
                            ->scalarNode('format')
                                ->defaultValue('jpg')
                                ->info('Image format for transformer placeholders. Null uses transformer default.')
                            ->end()
                            ->integerNode('components_x')->defaultValue(4)->min(1)->max(9)->info('Horizontal BlurHash components (1–9).')->end()
                            ->integerNode('components_y')->defaultValue(3)->min(1)->max(9)->info('Vertical BlurHash components (1–9).')->end()
                            ->scalarNode('driver')
                                ->defaultValue('gd')
                                ->validate()
                                    ->ifNotInArray(['gd', 'imagick'])
                                    ->thenInvalid('Driver must be "gd" or "imagick"')
                                ->end()
                                ->info('Image processing driver for BlurHash (gd or imagick).')
                            ->end()
                            ->scalarNode('service')
                                ->defaultNull()
                                ->info('Service ID for custom placeholders (type: service).')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('loaders')
                    ->useAttributeAsKey('name')
                    ->defaultValue([])
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->enumNode('type')
                                ->values(['filesystem', 'flysystem', 'vich', 'url', 'chain'])
                                ->defaultNull()
                                ->info('Loader type. Inferred from name when it matches a known type.')
                            ->end()
                            ->scalarNode('path')
                                ->defaultNull()
                                ->info('Directory a filesystem loader reads images from.')
                            ->end()
                            ->variableNode('paths')
                                ->defaultNull()
                                ->info('Removed in 2.0: declare one filesystem loader per directory, each with its own "path".')
                            ->end()
                            ->arrayNode('loaders')
                                ->scalarPrototype()->end()
                                ->info('Loaders a chain loader tries, in order: each image is rendered with the first one holding it.')
                            ->end()
                            ->scalarNode('mapping')
                                ->defaultNull()
                                ->info('VichUploader mapping a vich loader serves. Defaults to the loader name when it is a mapping, or to the only mapping.')
                            ->end()
                            ->scalarNode('storage')
                                ->defaultNull()
                                ->info('Flysystem storage service ID.')
                            ->end()
                            ->scalarNode('http_client')
                                ->defaultNull()
                                ->info('PSR-18 HTTP client service ID for url loaders.')
                            ->end()
                            ->scalarNode('request_factory')
                                ->defaultNull()
                                ->info('PSR-17 request factory service ID for url loaders.')
                            ->end()
                            ->scalarNode('default_placeholder')
                                ->defaultNull()
                                ->info('Default placeholder name for this loader. Overrides the global default_placeholder.')
                            ->end()
                            ->scalarNode('default_transformer')
                                ->defaultNull()
                                ->info('Default transformer name for this loader. Overrides the global default_transformer.')
                            ->end()
                            ->scalarNode('resolve_metadata')
                                ->defaultNull()
                                ->info('Whether to resolve image metadata for this loader. Null inherits from global. Filesystem loaders default to true.')
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => null !== $v && !is_bool($v))
                                    ->thenInvalid('The "resolve_metadata" option must be null or a boolean.')
                                ->end()
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $v): bool => 'flysystem' === $v['type'] && (null === $v['storage'] || '' === $v['storage']))
                            ->thenInvalid('A flysystem loader requires a "storage" service ID.')
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $v): bool => 'filesystem' === $v['type'] && null !== $v['storage'])
                            ->thenInvalid('The "storage" option is not supported for filesystem loaders. Use "path" instead.')
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $v): bool => null !== $v['paths'])
                            ->thenInvalid('The "paths" option was removed in 2.0. Declare one filesystem loader per directory, each with its own "path" (e.g. "uploads: { type: filesystem, path: \'%%kernel.project_dir%%/public/uploads\' }"), then pick the loader in your templates, or keep this name for all of them with a chain loader (e.g. "filesystem: { type: chain, loaders: [uploads, assets] }").')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('transformers')
                    ->useAttributeAsKey('name')
                    ->defaultValue([])
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->enumNode('type')
                                ->values(['glide', 'imgix', 'service'])
                                ->defaultNull()
                                ->info('Transformer type. Inferred from name when it matches a known type.')
                            ->end()
                            ->scalarNode('sign_key')->defaultNull()->end()
                            ->scalarNode('cache')->defaultNull()->info('Glide cache target. Either a local path or a Flysystem storage name (resolved via FlysystemRegistry).')->end()
                            ->scalarNode('driver')
                                ->defaultValue('gd')
                                ->validate()
                                    ->ifNotInArray(['gd', 'imagick', 'vips'])
                                    ->thenInvalid('Driver must be "gd", "imagick" or "vips"')
                                ->end()
                            ->end()
                            ->integerNode('max_image_size')->defaultNull()->info('Max image size for glide.')->end()
                            ->scalarNode('base_url')->defaultNull()->info('Imgix: source domain (e.g. https://my-source.imgix.net). Glide: optional scheme and host prepended to generated image URLs, e.g. a CDN (https://img.example.com).')->end()
                            ->scalarNode('api_key')->defaultNull()->info('Imgix API key for cache purge operations.')->end()
                            ->scalarNode('http_client')->defaultNull()->info('PSR-18 HTTP client service ID for imgix purge.')->end()
                            ->scalarNode('request_factory')->defaultNull()->info('PSR-17 request factory service ID for imgix purge.')->end()
                            ->scalarNode('stream_factory')->defaultNull()->info('PSR-17 stream factory service ID for imgix purge.')->end()
                            ->scalarNode('service')->defaultNull()->info('Service ID for custom transformers (type: service).')->end()
                            ->booleanNode('defer_cache_write')
                                ->defaultFalse()
                                ->info('Glide: render a cache miss to local disk and move it to the cache storage after the response has been sent (kernel.terminate). For remote cache storages.')
                            ->end()
                            ->arrayNode('public_cache')
                                ->canBeEnabled()
                                ->children()
                                    ->scalarNode('prefix')
                                        ->defaultValue('')
                                        ->info('Path prepended to every cache key, so keys mirror the URL path (e.g. "image" when the bundle routes are served under /image and the cache storage is a bucket served at the site root).')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{
         *     default_loader: string|null,
         *     default_transformer: string|null,
         *     default_placeholder: string|null,
         *     resolve_metadata: bool,
         *     collector: bool,
         *     cache: bool|string,
         *     cache_control: CacheControlConfig,
         *     device_sizes: list<int>,
         *     image_sizes: list<int>,
         *     formats: list<string>,
         *     default_quality: int|null,
         *     default_fit: string,
         *     placeholders: array<string, array{enabled: bool, type: string|null, size: int, blur: int|null, quality: int|null, fit: string|null, format: string|null, components_x: int, components_y: int, driver: string, service: string|null}>,
         *     loaders: array<string, array{enabled: bool, type: string|null, path: string|null, paths: mixed, loaders: list<string>, mapping: string|null, storage: string|null, http_client: string|null, request_factory: string|null, default_placeholder: string|null, default_transformer: string|null, resolve_metadata: bool|null}>,
         *     transformers: array<string, array{enabled: bool, type: string|null, sign_key: string|null, cache: string|null, driver: string, max_image_size: int|null, base_url: string|null, api_key: string|null, http_client: string|null, request_factory: string|null, stream_factory: string|null, service: string|null, defer_cache_write: bool, public_cache: array{enabled: bool, prefix: string}}>
         * } $config
         */
        $services = $container->services();

        // --- MetadataGuesser ---

        $cacheServiceId = match (true) {
            true === $config['cache'] => 'cache.app',
            is_string($config['cache']) => $config['cache'],
            default => null,
        };
        $metadataGuesserDef = $services->set('picasso.metadata_guesser', MetadataGuesser::class);
        if (null !== $cacheServiceId) {
            $metadataGuesserDef->args([service($cacheServiceId)]);
        }
        $services->alias(MetadataGuesser::class, 'picasso.metadata_guesser');
        $services->alias(MetadataGuesserInterface::class, 'picasso.metadata_guesser');

        // --- Flysystem registry (shared by Vich loader + Glide cache) ---

        $hasFlysystem = interface_exists(\League\Flysystem\FilesystemOperator::class);
        if ($hasFlysystem) {
            $services->set('.picasso.flysystem_registry', FlysystemRegistry::class)
                ->args([tagged_locator('flysystem.storage', 'storage')]);
        }

        // --- Loaders ---

        $knownTypes = ['filesystem', 'flysystem', 'vich', 'url'];
        $vichHelperRegistered = false;
        /** @var array<string, string> $loaderPlaceholders */
        $loaderPlaceholders = [];
        /** @var array<string, string> $loaderTransformers */
        $loaderTransformers = [];
        /** @var array<string, bool> $loaderResolveMetadata */
        $loaderResolveMetadata = [];
        /** @var array<string, string> $loaderRoots Root → first loader reading it, for 1.x URLs (vich roots: VichLoaderPass) */
        $loaderRoots = [];

        foreach ($config['loaders'] as $name => $loaderConfig) {
            if (!$loaderConfig['enabled']) {
                continue;
            }

            $type = $loaderConfig['type'] ?? (in_array($name, $knownTypes, true) ? $name : null);

            if (null === $type) {
                throw new Exception\InvalidConfigurationException(sprintf('Loader "%s" must specify a "type" (%s).', $name, implode(', ', $knownTypes)));
            }

            if (null !== $loaderConfig['path'] && 'filesystem' !== $type) {
                throw new Exception\InvalidConfigurationException(sprintf('Loader "%s": the "path" option is only supported by filesystem loaders.', $name));
            }

            if (null !== $loaderConfig['mapping'] && 'vich' !== $type) {
                throw new Exception\InvalidConfigurationException(sprintf('Loader "%s": the "mapping" option is only supported by vich loaders.', $name));
            }

            if ([] !== $loaderConfig['loaders'] && 'chain' !== $type) {
                throw new Exception\InvalidConfigurationException(sprintf('Loader "%s": the "loaders" option is only supported by chain loaders.', $name));
            }

            $tag = ['key' => $name];
            if (null !== $loaderConfig['default_placeholder']) {
                $tag['default_placeholder'] = $loaderConfig['default_placeholder'];
                $loaderPlaceholders[$name] = $loaderConfig['default_placeholder'];
            }
            if (null !== $loaderConfig['default_transformer']) {
                $tag['default_transformer'] = $loaderConfig['default_transformer'];
                $loaderTransformers[$name] = $loaderConfig['default_transformer'];
            }

            // Auto-set resolve_metadata to true for filesystem loaders when not explicitly configured
            $resolveMetadata = $loaderConfig['resolve_metadata'] ?? ('filesystem' === $type ? true : null);
            if (null !== $resolveMetadata) {
                $tag['resolve_metadata'] = $resolveMetadata;
                $loaderResolveMetadata[$name] = $resolveMetadata;
            }

            switch ($type) {
                case 'filesystem':
                    if (null === $loaderConfig['path'] || '' === $loaderConfig['path']) {
                        throw new Exception\InvalidConfigurationException(sprintf('Loader "%s": a filesystem loader requires a "path", the directory it reads images from (e.g. "%%kernel.project_dir%%/public/uploads").', $name));
                    }

                    $services->set('picasso.loader.' . $name, FilesystemLoader::class)
                        ->args([$loaderConfig['path']])
                        ->tag('picasso.loader', $tag);
                    $loaderRoots[$loaderConfig['path']] ??= $name;
                    break;

                case 'flysystem':
                    assert(is_string($loaderConfig['storage']));
                    $services->set('picasso.loader.' . $name, FlysystemLoader::class)
                        ->args([
                            service($loaderConfig['storage']),
                        ])
                        ->tag('picasso.loader', $tag);
                    break;

                case 'url':
                    $httpClientService = $loaderConfig['http_client'] ?? 'psr18.http_client';
                    $services->set('picasso.loader.' . $name, UrlLoader::class)
                        ->args([
                            service($httpClientService),
                            service($loaderConfig['request_factory'] ?? $httpClientService),
                        ])
                        ->tag('picasso.loader', $tag);
                    break;

                case 'vich':
                    if (interface_exists(VichStorageInterface::class)) {
                        if (!$vichHelperRegistered) {
                            $services->set('.picasso.vich_mapping_helper', VichMappingHelper::class)
                                ->args([service(\Vich\UploaderBundle\Mapping\PropertyMappingFactory::class)]);
                            $vichHelperRegistered = true;
                        }

                        // The mapping and its upload destination are resolved against the
                        // VichUploader configuration by VichLoaderPass, once it is loaded.
                        $services->set('picasso.loader.' . $name, VichUploaderLoader::class)
                            ->args([
                                service(VichStorageInterface::class),
                                service('.picasso.vich_mapping_helper'),
                                '',
                                '',
                                $hasFlysystem ? service('.picasso.flysystem_registry') : null,
                            ])
                            ->tag('picasso.loader', $tag)
                            ->tag(VichLoaderPass::TAG, ['loader' => $name, 'mapping' => $loaderConfig['mapping']]);
                    }
                    break;

                case 'chain':
                    $chain = $loaderConfig['loaders'];
                    $this->checkChain($name, $chain, $config['loaders'], $knownTypes);

                    $services->set('picasso.loader.' . $name, ChainLoader::class)
                        ->args([
                            $name,
                            service_locator(array_combine($chain, array_map(static fn (string $loader): ReferenceConfigurator => service('picasso.loader.' . $loader), $chain))),
                            $chain,
                        ])
                        ->tag('picasso.loader', $tag);
                    break;
            }
        }

        // Alias default loader (auto-detect when exactly one is enabled)
        $defaultLoader = $this->autoDetectDefault($config['default_loader'], $config['loaders'], 'loader');

        if (null !== $defaultLoader) {
            $services->alias('picasso.default_loader', 'picasso.loader.' . $defaultLoader);
            $services->alias(ImageLoaderInterface::class, 'picasso.loader.' . $defaultLoader);
        }

        // --- Registries ---

        $services->set('picasso.loader_registry', LoaderRegistry::class)
            ->args([tagged_locator('picasso.loader', 'key'), $loaderPlaceholders, $loaderTransformers, $loaderResolveMetadata]);
        $services->alias(LoaderRegistry::class, 'picasso.loader_registry');

        $services->set('picasso.transformer_registry', TransformerRegistry::class)
            ->args([tagged_locator('picasso.transformer', 'key')]);
        $services->alias(TransformerRegistry::class, 'picasso.transformer_registry');

        $services->set('picasso.placeholder_registry', PlaceholderRegistry::class)
            ->args([tagged_locator('picasso.placeholder', 'key')]);
        $services->alias(PlaceholderRegistry::class, 'picasso.placeholder_registry');

        // --- Transformers ---

        $knownTransformerTypes = ['glide', 'imgix', 'service'];
        $deferredCacheWriterRegistered = false;
        $glideTransformers = [];
        $glideSignKey = null;

        foreach ($config['transformers'] as $name => $transformerConfig) {
            if (!$transformerConfig['enabled']) {
                continue;
            }

            $type = $transformerConfig['type'] ?? (in_array($name, $knownTransformerTypes, true) ? $name : null);

            if (null === $type) {
                throw new Exception\InvalidConfigurationException(sprintf('Transformer "%s" must specify a "type" (glide, imgix, or service).', $name));
            }

            switch ($type) {
                case 'glide':
                    $services->set('picasso.transformer.' . $name, GlideTransformer::class)
                        ->args([
                            service('router'),
                            $transformerConfig['sign_key'],
                            $transformerConfig['cache'] ?? '%kernel.project_dir%/var/glide-cache',
                            $transformerConfig['driver'],
                            $transformerConfig['max_image_size'],
                            $transformerConfig['public_cache']['enabled'],
                            $hasFlysystem ? service('.picasso.flysystem_registry') : null,
                            $transformerConfig['base_url'],
                            $transformerConfig['public_cache']['prefix'],
                            $transformerConfig['defer_cache_write'] ? service('picasso.deferred_cache_writer') : null,
                        ])
                        ->tag('picasso.transformer', ['key' => $name]);
                    $glideTransformers[$name] = service('picasso.transformer.' . $name);
                    $glideSignKey ??= (string) $transformerConfig['sign_key'];

                    if ($transformerConfig['defer_cache_write'] && !$deferredCacheWriterRegistered) {
                        $services->set('picasso.deferred_cache_writer', DeferredCacheWriter::class)
                            ->args([service('logger')->nullOnInvalid()])
                            ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'flush'])
                            ->tag('kernel.reset', ['method' => 'reset'])
                            ->tag('monolog.logger', ['channel' => 'picasso']);
                        $deferredCacheWriterRegistered = true;
                    }

                    break;

                case 'imgix':
                    $imgixArgs = [
                        $transformerConfig['base_url'],
                        $transformerConfig['sign_key'],
                    ];

                    if (null !== $transformerConfig['api_key']) {
                        $httpClientService = $transformerConfig['http_client'] ?? 'psr18.http_client';
                        $imgixArgs[] = $transformerConfig['api_key'];
                        $imgixArgs[] = service($httpClientService);
                        $imgixArgs[] = service($transformerConfig['request_factory'] ?? $httpClientService);
                        $imgixArgs[] = service($transformerConfig['stream_factory'] ?? $httpClientService);
                    }

                    $services->set('picasso.transformer.' . $name, ImgixTransformer::class)
                        ->args($imgixArgs)
                        ->tag('picasso.transformer', ['key' => $name]);
                    break;

                case 'service':
                    assert(is_string($transformerConfig['service']), sprintf('Transformer "%s" of type "service" must specify a "service" ID.', $name));
                    $builder->setDefinition(
                        'picasso.transformer.' . $name,
                        (new ChildDefinition($transformerConfig['service']))
                            ->addTag('picasso.transformer', ['key' => $name]),
                    );
                    break;
            }
        }

        // Alias default transformer (auto-detect when exactly one is enabled)
        $defaultTransformer = $this->autoDetectDefault($config['default_transformer'], $config['transformers'], 'transformer');

        if (null !== $defaultTransformer) {
            $services->alias('picasso.default_transformer', 'picasso.transformer.' . $defaultTransformer);
            $services->alias(ImageTransformerInterface::class, 'picasso.transformer.' . $defaultTransformer);
        }

        // --- Placeholders ---

        $knownPlaceholderTypes = ['transformer', 'blurhash', 'service'];

        foreach ($config['placeholders'] as $name => $placeholderConfig) {
            if (!$placeholderConfig['enabled']) {
                continue;
            }

            $type = $placeholderConfig['type'] ?? (in_array($name, $knownPlaceholderTypes, true) ? $name : null);

            if (null === $type) {
                throw new Exception\InvalidConfigurationException(sprintf('Placeholder "%s" must specify a "type" (transformer, blurhash, or service).', $name));
            }

            switch ($type) {
                case 'transformer':
                    $services->set('picasso.placeholder.' . $name, TransformerPlaceholder::class)
                        ->args([
                            service('picasso.transformer_registry'),
                            $placeholderConfig['size'],
                            $placeholderConfig['blur'],
                            $placeholderConfig['quality'],
                            $placeholderConfig['fit'],
                            $placeholderConfig['format'],
                        ])
                        ->tag('picasso.placeholder', ['key' => $name]);
                    break;

                case 'blurhash':
                    if (!interface_exists(\Imagine\Image\ImagineInterface::class)) {
                        throw new Exception\InvalidConfigurationException(sprintf('Placeholder "%s" of type "blurhash" requires the "imagine/imagine" package. Install it with: composer require imagine/imagine', $name));
                    }
                    $imagineClass = 'imagick' === $placeholderConfig['driver']
                        ? \Imagine\Imagick\Imagine::class
                        : \Imagine\Gd\Imagine::class;
                    $imagineServiceId = 'picasso.imagine.' . $name;
                    $services->set($imagineServiceId, $imagineClass);

                    $blurhashArgs = [
                        service($imagineServiceId),
                        $placeholderConfig['components_x'],
                        $placeholderConfig['components_y'],
                        $placeholderConfig['size'],
                    ];
                    if (null !== $cacheServiceId) {
                        $blurhashArgs[] = service($cacheServiceId);
                    } else {
                        $blurhashArgs[] = null;
                    }
                    $blurhashArgs[] = service('debug.stopwatch')->nullOnInvalid();

                    $services->set('picasso.placeholder.' . $name, BlurHashPlaceholder::class)
                        ->args($blurhashArgs)
                        ->tag('picasso.placeholder', ['key' => $name]);
                    break;

                case 'service':
                    assert(is_string($placeholderConfig['service']), sprintf('Placeholder "%s" of type "service" must specify a "service" ID.', $name));
                    $builder->setDefinition(
                        'picasso.placeholder.' . $name,
                        (new ChildDefinition($placeholderConfig['service']))
                            ->addTag('picasso.placeholder', ['key' => $name]),
                    );
                    break;
            }
        }

        // Alias default placeholder (auto-detect when exactly one is enabled)
        $defaultPlaceholder = $this->autoDetectDefault($config['default_placeholder'], $config['placeholders'], 'placeholder');

        if (null !== $defaultPlaceholder) {
            $services->alias('picasso.default_placeholder', 'picasso.placeholder.' . $defaultPlaceholder);
            $services->alias(PlaceholderInterface::class, 'picasso.placeholder.' . $defaultPlaceholder);
        }

        // --- Controller ---

        $services->set('picasso.controller.image', ImageController::class)
            ->args([
                service('picasso.transformer_registry'),
                service('picasso.loader_registry'),
                service('debug.stopwatch')->nullOnInvalid(),
                $config['cache_control'],
            ])
            ->tag('controller.service_arguments')
            ->public();

        // 1.x Glide URLs carry the root of their source in "_metadata", 1.x
        // encrypted it with the sign key of the first Glide transformer
        if ([] !== $glideTransformers) {
            $services->set(LegacyMetadataResolver::SERVICE, LegacyMetadataResolver::class)
                ->args([$glideSignKey, $loaderRoots, '%kernel.project_dir%']);

            $services->set('.picasso.controller.legacy_url', LegacyUrlController::class)
                ->decorate('picasso.controller.image')
                ->args([
                    service('.inner'),
                    service_locator($glideTransformers),
                    service(LegacyMetadataResolver::SERVICE),
                    $config['cache_control']['error_max_age'],
                ])
                ->tag('controller.service_arguments');
        }

        // --- Pipeline ---

        $services->set('picasso.pipeline', ImagePipeline::class)
            ->args([
                service('picasso.loader_registry'),
                service('picasso.transformer_registry'),
                $defaultLoader,
                $defaultTransformer,
            ]);
        $services->alias(ImagePipeline::class, 'picasso.pipeline');

        // --- Srcset Generator ---

        $services->set('picasso.srcset_generator', SrcsetGenerator::class)
            ->args([
                $config['device_sizes'],
                $config['image_sizes'],
                $config['default_quality'],
                $config['default_fit'],
            ]);
        $services->alias(SrcsetGenerator::class, 'picasso.srcset_generator');

        // --- Image Helper ---

        $services->set('picasso.image_helper', ImageHelper::class)
            ->args([
                service('picasso.pipeline'),
                service('picasso.srcset_generator'),
                service('picasso.transformer_registry'),
                service('picasso.metadata_guesser'),
                service('picasso.placeholder_registry'),
                service('picasso.loader_registry'),
                $config['formats'],
                $config['default_quality'],
                $config['default_fit'],
                $defaultPlaceholder,
                $config['resolve_metadata'],
                service('debug.stopwatch')->nullOnInvalid(),
            ]);
        $services->alias(ImageHelper::class, 'picasso.image_helper');
        $services->alias(ImageHelperInterface::class, 'picasso.image_helper');

        // --- Twig Extension ---

        $services->set('.picasso.twig_extension', PicassoExtension::class)
            ->args([
                service('picasso.image_helper'),
            ])
            ->tag('twig.extension');

        // --- Image Component ---

        $services->set('.picasso.image_component', ImageComponent::class)
            ->args([
                service('picasso.image_helper'),
            ])
            ->tag('twig.component', [
                'key' => 'Picasso:Image',
                'template' => '@Picasso/components/Image.html.twig',
            ]);

        // --- Data Collector (web profiler) ---

        if ($config['collector']) {
            $services->set('picasso.data_collector', PicassoDataCollector::class)
                ->tag('data_collector', [
                    'id' => 'picasso',
                    'template' => '@Picasso/Collector/picasso.html.twig',
                ]);

            $services->set('.picasso.image_helper.collecting', CollectingImageHelper::class)
                ->decorate('picasso.image_helper')
                ->args([
                    service('.picasso.image_helper.collecting.inner'),
                    service('picasso.data_collector'),
                    service('picasso.pipeline'),
                ]);

            $services->set('.picasso.metadata_guesser.collecting', CollectingMetadataGuesser::class)
                ->decorate('picasso.metadata_guesser')
                ->args([
                    service('.picasso.metadata_guesser.collecting.inner'),
                    service('picasso.data_collector'),
                ]);
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', [
                'paths' => [
                    dirname(__DIR__) . '/templates' => 'Picasso',
                ],
            ]);
        }
    }

    /**
     * A chain lists servable loaders declared in the bundle configuration: it
     * asks their source whether they hold an image.
     *
     * @param list<string>                                                $chain
     * @param array<string, array{enabled: bool, type: string|null, ...}> $loaders
     * @param list<string>                                                $knownTypes
     */
    private function checkChain(string $name, array $chain, array $loaders, array $knownTypes): void
    {
        if ([] === $chain) {
            throw new Exception\InvalidConfigurationException(sprintf('Loader "%s": a chain loader requires "loaders", the loaders it tries in order (e.g. "loaders: [uploads, assets]").', $name));
        }

        foreach ($chain as $loader) {
            $type = isset($loaders[$loader]) && $loaders[$loader]['enabled']
                ? $loaders[$loader]['type'] ?? (in_array($loader, $knownTypes, true) ? $loader : null)
                : null;

            if (!in_array($type, ['filesystem', 'flysystem', 'vich'], true)) {
                throw new Exception\InvalidConfigurationException(sprintf('Loader "%s": chain member "%s" must be a filesystem, flysystem or vich loader declared under "picasso.loaders".', $name, $loader));
            }
        }
    }

    /**
     * Auto-detect a default name when only one item is enabled.
     *
     * @param array<string, array{enabled: bool, ...}> $items
     */
    private function autoDetectDefault(?string $explicit, array $items, string $label): ?string
    {
        if (null !== $explicit) {
            if (!isset($items[$explicit])) {
                throw new Exception\InvalidConfigurationException(sprintf('The default %s "%s" does not exist. Available: %s.', $label, $explicit, '' !== implode(', ', array_keys($items)) ? implode(', ', array_keys($items)) : 'none'));
            }

            if (!$items[$explicit]['enabled']) {
                throw new Exception\InvalidConfigurationException(sprintf('The default %s "%s" is disabled.', $label, $explicit));
            }

            return $explicit;
        }

        $enabled = array_keys(array_filter($items, static fn (array $v): bool => $v['enabled']));

        return 1 === count($enabled) ? $enabled[0] : null;
    }
}
