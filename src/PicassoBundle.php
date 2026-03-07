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
use function is_string;

use LogicException;
use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Attribute\AsImageTransformer;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Loader\FilesystemLoader;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;
use Silarhi\PicassoBundle\Loader\FlysystemRegistry;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\UrlLoader;
use Silarhi\PicassoBundle\Loader\VichMappingHelper;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\Service\ImageHelper;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\MetadataGuesser;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
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
                $definition->addTag('picasso.loader', ['key' => $attribute->name]);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsImageTransformer::class,
            static function (ChildDefinition $definition, AsImageTransformer $attribute): void {
                $definition->addTag('picasso.transformer', ['key' => $attribute->name]);
            },
        );
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
                ->integerNode('default_quality')
                    ->defaultValue(75)
                    ->min(1)
                    ->max(100)
                ->end()
                ->scalarNode('default_fit')
                    ->defaultValue('contain')
                    ->info('Default fit mode (contain, cover, crop, fill).')
                ->end()
                ->arrayNode('placeholders')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('blur')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->integerNode('size')->defaultValue(10)->end()
                                ->integerNode('blur')->defaultValue(5)->end()
                                ->integerNode('quality')->defaultValue(30)->min(1)->max(100)->end()
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
                                ->info('HTTP client service ID for url loaders.')
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
                                ->children()
                                    ->scalarNode('path')
                                        ->defaultValue('%kernel.project_dir%/public/cache/picasso')
                                        ->info('Filesystem path to write cached images for direct web server serving.')
                                    ->end()
                                    ->scalarNode('url_prefix')
                                        ->defaultValue('/cache/picasso')
                                        ->info('URL prefix for cached images (must match the route prefix).')
                                    ->end()
                                ->end()
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
         *     device_sizes: list<int>,
         *     image_sizes: list<int>,
         *     formats: list<string>,
         *     default_quality: int,
         *     default_fit: string,
         *     placeholders: array{blur: array{enabled: bool, size: int, blur: int, quality: int}},
         *     loaders: array<string, array{enabled: bool, type: string|null, paths: list<string>, storage: string|null, http_client: string|null}>,
         *     transformers: array<string, array{enabled: bool, type: string|null, sign_key: string|null, cache: string|null, driver: string, max_image_size: int|null, base_url: string|null, service: string|null, public_cache: array{enabled: bool, path: string, url_prefix: string}}>
         * } $config
         */
        $services = $container->services();

        // --- MetadataGuesser ---

        $services->set('picasso.metadata_guesser', MetadataGuesser::class);
        $services->alias(MetadataGuesser::class, 'picasso.metadata_guesser');
        $services->alias(MetadataGuesserInterface::class, 'picasso.metadata_guesser');

        // --- Loaders ---

        $knownTypes = ['filesystem', 'flysystem', 'vich', 'url'];
        $vichHelperRegistered = false;

        foreach ($config['loaders'] as $name => $loaderConfig) {
            if (!$loaderConfig['enabled']) {
                continue;
            }

            $type = $loaderConfig['type'] ?? (in_array($name, $knownTypes, true) ? $name : null);

            if (null === $type) {
                throw new LogicException(sprintf('Loader "%s" must specify a "type" (filesystem, flysystem, or vich).', $name));
            }

            switch ($type) {
                case 'filesystem':
                    $services->set('picasso.loader.' . $name, FilesystemLoader::class)
                        ->args([$loaderConfig['paths']])
                        ->tag('picasso.loader', ['key' => $name]);
                    break;

                case 'flysystem':
                    assert(is_string($loaderConfig['storage']));
                    $services->set('picasso.loader.' . $name, FlysystemLoader::class)
                        ->args([
                            service($loaderConfig['storage']),
                        ])
                        ->tag('picasso.loader', ['key' => $name]);
                    break;

                case 'url':
                    $services->set('picasso.loader.' . $name, UrlLoader::class)
                        ->args([service($loaderConfig['http_client'] ?? 'http_client')])
                        ->tag('picasso.loader', ['key' => $name]);
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
                            ->tag('picasso.loader', ['key' => $name]);
                    }
                    break;
            }
        }

        // Alias default loader (auto-detect when exactly one is enabled)
        $defaultLoader = $config['default_loader'];
        if (null === $defaultLoader) {
            $enabledLoaders = array_keys(array_filter($config['loaders'], static fn (array $v): bool => $v['enabled']));
            if (1 === count($enabledLoaders)) {
                $defaultLoader = $enabledLoaders[0];
            }
        }

        if (null !== $defaultLoader) {
            $services->alias('picasso.default_loader', 'picasso.loader.' . $defaultLoader);
            $services->alias(ImageLoaderInterface::class, 'picasso.loader.' . $defaultLoader);
        }

        // --- Registries ---

        $services->set('picasso.loader_registry', LoaderRegistry::class)
            ->args([tagged_locator('picasso.loader', 'key')]);
        $services->alias(LoaderRegistry::class, 'picasso.loader_registry');

        $services->set('picasso.transformer_registry', TransformerRegistry::class)
            ->args([tagged_locator('picasso.transformer', 'key')]);
        $services->alias(TransformerRegistry::class, 'picasso.transformer_registry');

        // --- Transformers ---

        $knownTransformerTypes = ['glide', 'imgix', 'service'];
        $urlEncryptionRegistered = false;

        foreach ($config['transformers'] as $name => $transformerConfig) {
            if (!$transformerConfig['enabled']) {
                continue;
            }

            $type = $transformerConfig['type'] ?? (in_array($name, $knownTransformerTypes, true) ? $name : null);

            if (null === $type) {
                throw new LogicException(sprintf('Transformer "%s" must specify a "type" (glide, imgix, or service).', $name));
            }

            switch ($type) {
                case 'glide':
                    if (!$urlEncryptionRegistered) {
                        $services->set('picasso.url_encryption', UrlEncryption::class)
                            ->args([$transformerConfig['sign_key']]);
                        $services->alias(UrlEncryption::class, 'picasso.url_encryption');
                        $urlEncryptionRegistered = true;
                    }

                    $publicCache = $transformerConfig['public_cache']['enabled']
                        ? $transformerConfig['public_cache']
                        : null;

                    $services->set('picasso.transformer.' . $name, GlideTransformer::class)
                        ->args([
                            service('router'),
                            service('picasso.url_encryption'),
                            $transformerConfig['sign_key'],
                            $transformerConfig['cache'] ?? '%kernel.project_dir%/var/glide-cache',
                            $transformerConfig['driver'],
                            $transformerConfig['max_image_size'],
                            $publicCache,
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
        $defaultTransformer = $config['default_transformer'];
        if (null === $defaultTransformer) {
            $enabledTransformers = array_keys(array_filter($config['transformers'], static fn (array $v): bool => $v['enabled']));
            if (1 === count($enabledTransformers)) {
                $defaultTransformer = $enabledTransformers[0];
            }
        }

        if (null !== $defaultTransformer) {
            $services->alias('picasso.default_transformer', 'picasso.transformer.' . $defaultTransformer);
            $services->alias(ImageTransformerInterface::class, 'picasso.transformer.' . $defaultTransformer);
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
            ]);
        $services->alias(SrcsetGenerator::class, 'picasso.srcset_generator');

        // --- Image Helper ---

        $services->set('picasso.image_helper', ImageHelper::class)
            ->args([
                service('picasso.pipeline'),
                $config['default_quality'],
                $config['default_fit'],
            ]);
        $services->alias(ImageHelper::class, 'picasso.image_helper');

        // --- Twig Extension ---

        $services->set('.picasso.twig_extension', PicassoExtension::class)
            ->args([
                service('picasso.image_helper'),
            ])
            ->tag('twig.extension');

        // --- Image Component ---

        $blurConfig = $config['placeholders']['blur'];

        $services->set('.picasso.image_component', ImageComponent::class)
            ->args([
                service('picasso.srcset_generator'),
                service('picasso.pipeline'),
                service('picasso.transformer_registry'),
                service('picasso.metadata_guesser'),
                $config['formats'],
                $config['default_quality'],
                $config['default_fit'],
                $blurConfig['enabled'],
                $blurConfig['size'],
                $blurConfig['blur'],
                $blurConfig['quality'],
                service('debug.stopwatch')->nullOnInvalid(),
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
}
