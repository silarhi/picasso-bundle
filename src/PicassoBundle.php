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

use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Attribute\AsImageTransformer;
use Silarhi\PicassoBundle\Attribute\AsPlaceholder;
use Silarhi\PicassoBundle\Controller\ImageController;
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
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\MetadataGuesser;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Silarhi\PicassoBundle\Service\PlaceholderRegistry;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Service\UrlEncryption;
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

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
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

                foreach ($container->findTaggedServiceIds('picasso.loader') as $tags) {
                    /** @var array{key?: string, default_placeholder?: string, default_transformer?: string} $tag */
                    foreach ($tags as $tag) {
                        if (isset($tag['key'], $tag['default_placeholder'])) {
                            $placeholders[$tag['key']] ??= $tag['default_placeholder'];
                        }
                        if (isset($tag['key'], $tag['default_transformer'])) {
                            $transformers[$tag['key']] ??= $tag['default_transformer'];
                        }
                    }
                }

                $definition->replaceArgument(1, $placeholders);
                $definition->replaceArgument(2, $transformers);
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
                                ->values(['filesystem', 'flysystem', 'vich', 'url'])
                                ->defaultNull()
                                ->info('Loader type. Inferred from name when it matches a known type.')
                            ->end()
                            ->arrayNode('paths')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                                ->info('Base directories for filesystem loaders.')
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
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $v): bool => 'flysystem' === $v['type'] && (null === $v['storage'] || '' === $v['storage']))
                            ->thenInvalid('A flysystem loader requires a "storage" service ID.')
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $v): bool => 'filesystem' === $v['type'] && null !== $v['storage'])
                            ->thenInvalid('The "storage" option is not supported for filesystem loaders. Use "paths" instead.')
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
                            ->scalarNode('cache')->defaultNull()->info('Cache directory for glide.')->end()
                            ->scalarNode('driver')
                                ->defaultValue('gd')
                                ->validate()
                                    ->ifNotInArray(['gd', 'imagick'])
                                    ->thenInvalid('Driver must be "gd" or "imagick"')
                                ->end()
                            ->end()
                            ->integerNode('max_image_size')->defaultNull()->info('Max image size for glide.')->end()
                            ->scalarNode('base_url')->defaultNull()->info('Base URL for imgix (e.g. https://my-source.imgix.net).')->end()
                            ->scalarNode('service')->defaultNull()->info('Service ID for custom transformers (type: service).')->end()
                            ->arrayNode('public_cache')
                                ->canBeEnabled()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{
         *     default_loader: string|null,
         *     default_transformer: string|null,
         *     default_placeholder: string|null,
         *     cache: bool|string,
         *     device_sizes: list<int>,
         *     image_sizes: list<int>,
         *     formats: list<string>,
         *     default_quality: int|null,
         *     default_fit: string,
         *     placeholders: array<string, array{enabled: bool, type: string|null, size: int, blur: int|null, quality: int|null, fit: string|null, format: string|null, components_x: int, components_y: int, driver: string, service: string|null}>,
         *     loaders: array<string, array{enabled: bool, type: string|null, paths: list<string>, storage: string|null, http_client: string|null, request_factory: string|null, default_placeholder: string|null, default_transformer: string|null}>,
         *     transformers: array<string, array{enabled: bool, type: string|null, sign_key: string|null, cache: string|null, driver: string, max_image_size: int|null, base_url: string|null, service: string|null, public_cache: array{enabled: bool}}>
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

        // --- Loaders ---

        $knownTypes = ['filesystem', 'flysystem', 'vich', 'url'];
        $vichHelperRegistered = false;
        /** @var array<string, string> $loaderPlaceholders */
        $loaderPlaceholders = [];
        /** @var array<string, string> $loaderTransformers */
        $loaderTransformers = [];

        foreach ($config['loaders'] as $name => $loaderConfig) {
            if (!$loaderConfig['enabled']) {
                continue;
            }

            $type = $loaderConfig['type'] ?? (in_array($name, $knownTypes, true) ? $name : null);

            if (null === $type) {
                throw new Exception\InvalidConfigurationException(sprintf('Loader "%s" must specify a "type" (filesystem, flysystem, or vich).', $name));
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

            switch ($type) {
                case 'filesystem':
                    $services->set('picasso.loader.' . $name, FilesystemLoader::class)
                        ->args([$loaderConfig['paths']])
                        ->tag('picasso.loader', $tag);
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
                            $services->set('.picasso.flysystem_registry', FlysystemRegistry::class)
                                ->args([tagged_locator('flysystem.storage', 'storage')]);
                            $vichHelperRegistered = true;
                        }

                        $services->set('picasso.loader.' . $name, VichUploaderLoader::class)
                            ->args([
                                service(VichStorageInterface::class),
                                service('.picasso.vich_mapping_helper'),
                                service('.picasso.flysystem_registry'),
                            ])
                            ->tag('picasso.loader', $tag);
                    }
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
            ->args([tagged_locator('picasso.loader', 'key'), $loaderPlaceholders, $loaderTransformers]);
        $services->alias(LoaderRegistry::class, 'picasso.loader_registry');

        $services->set('picasso.transformer_registry', TransformerRegistry::class)
            ->args([tagged_locator('picasso.transformer', 'key')]);
        $services->alias(TransformerRegistry::class, 'picasso.transformer_registry');

        $services->set('picasso.placeholder_registry', PlaceholderRegistry::class)
            ->args([tagged_locator('picasso.placeholder', 'key')]);
        $services->alias(PlaceholderRegistry::class, 'picasso.placeholder_registry');

        // --- Transformers ---

        $knownTransformerTypes = ['glide', 'imgix', 'service'];
        $urlEncryptionRegistered = false;

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
                    if (!$urlEncryptionRegistered) {
                        $services->set('picasso.url_encryption', UrlEncryption::class)
                            ->args([$transformerConfig['sign_key']]);
                        $services->alias(UrlEncryption::class, 'picasso.url_encryption');
                        $urlEncryptionRegistered = true;
                    }

                    $services->set('picasso.transformer.' . $name, GlideTransformer::class)
                        ->args([
                            service('router'),
                            service('picasso.url_encryption'),
                            $transformerConfig['sign_key'],
                            $transformerConfig['cache'] ?? '%kernel.project_dir%/var/glide-cache',
                            $transformerConfig['driver'],
                            $transformerConfig['max_image_size'],
                            $transformerConfig['public_cache']['enabled'],
                        ])
                        ->tag('picasso.transformer', ['key' => $name]);
                    break;

                case 'imgix':
                    $services->set('picasso.transformer.' . $name, ImgixTransformer::class)
                        ->args([
                            $transformerConfig['base_url'],
                            $transformerConfig['sign_key'],
                        ])
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
            ])
            ->tag('controller.service_arguments')
            ->public();

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
     * Auto-detect a default name when only one item is enabled.
     *
     * @param array<string, array{enabled: bool, ...}> $items
     */
    private function autoDetectDefault(?string $explicit, array $items, string $label): ?string
    {
        if (null !== $explicit) {
            if (!isset($items[$explicit])) {
                throw new Exception\InvalidConfigurationException(sprintf('The default %s "%s" does not exist. Available: %s.', $label, $explicit, implode(', ', array_keys($items)) ?: 'none'));
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
