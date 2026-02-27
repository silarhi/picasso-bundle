<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle;

use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Attribute\AsImageTransformer;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Loader\FilesystemLoader;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\VichMappingHelper;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
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
                            ->thenInvalid('Invalid format "%s". Allowed: '.implode(', ', $allowedFormats))
                        ->end()
                    ->end()
                ->end()
                ->integerNode('default_quality')
                    ->defaultValue(75)
                    ->min(1)->max(100)
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
                                ->integerNode('blur')->defaultValue(50)->end()
                                ->integerNode('quality')->defaultValue(30)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('loaders')
                    ->useAttributeAsKey('name')
                    ->defaultValue([])
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('type')
                                ->values(['filesystem', 'flysystem', 'vich'])
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
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $v): bool => 'flysystem' === $v['type'] && (null === $v['storage'] || '' === $v['storage']))
                            ->thenInvalid('A flysystem loader requires a "storage" service ID.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('transformers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('glide')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('sign_key')->defaultNull()->end()
                                ->scalarNode('cache')->defaultNull()->end()
                                ->scalarNode('driver')
                                    ->defaultValue('gd')
                                    ->validate()
                                        ->ifNotInArray(['gd', 'imagick'])
                                        ->thenInvalid('Driver must be "gd" or "imagick"')
                                    ->end()
                                ->end()
                                ->integerNode('max_image_size')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('imgix')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('domain')->defaultNull()->info('Your imgix source domain')->end()
                                ->scalarNode('sign_key')->defaultNull()->info('Imgix secure URL token')->end()
                                ->booleanNode('use_https')->defaultTrue()->end()
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
         *     loaders: array<string, array{type: string|null, paths: list<string>, storage: string|null}>,
         *     transformers: array{
         *         glide: array{enabled: bool, sign_key: string|null, cache: string|null, driver: string, max_image_size: int|null},
         *         imgix: array{enabled: bool, domain: string|null, sign_key: string|null, use_https: bool}
         *     }
         * } $config
         */
        $services = $container->services();

        // --- MetadataGuesser ---

        $services->set('picasso.metadata_guesser', MetadataGuesser::class);
        $services->alias(MetadataGuesser::class, 'picasso.metadata_guesser');
        $services->alias(MetadataGuesserInterface::class, 'picasso.metadata_guesser');

        // --- Loaders ---

        $knownTypes = ['filesystem', 'flysystem', 'vich'];
        $vichHelperRegistered = false;

        foreach ($config['loaders'] as $name => $loaderConfig) {
            $type = $loaderConfig['type'] ?? (\in_array($name, $knownTypes, true) ? $name : null);

            if (null === $type) {
                throw new \LogicException(\sprintf('Loader "%s" must specify a "type" (filesystem, flysystem, or vich).', $name));
            }

            switch ($type) {
                case 'filesystem':
                    $services->set('picasso.loader.'.$name, FilesystemLoader::class)
                        ->args([$loaderConfig['paths']])
                        ->tag('picasso.loader', ['key' => $name]);
                    break;

                case 'flysystem':
                    \assert(\is_string($loaderConfig['storage']));
                    $services->set('picasso.loader.'.$name, FlysystemLoader::class)
                        ->args([
                            service($loaderConfig['storage']),
                            service('picasso.metadata_guesser'),
                        ])
                        ->tag('picasso.loader', ['key' => $name]);
                    break;

                case 'vich':
                    if (interface_exists(VichStorageInterface::class)) {
                        if (!$vichHelperRegistered) {
                            $services->set('.picasso.vich_mapping_helper', VichMappingHelper::class)
                                ->args([service(\Vich\UploaderBundle\Mapping\PropertyMappingFactory::class)]);
                            $vichHelperRegistered = true;
                        }

                        $services->set('picasso.loader.'.$name, VichUploaderLoader::class)
                            ->args([
                                service(VichStorageInterface::class),
                                service('.picasso.vich_mapping_helper'),
                                service('picasso.metadata_guesser'),
                            ])
                            ->tag('picasso.loader', ['key' => $name]);
                    }
                    break;
            }
        }

        // Alias default loader
        if (null !== $config['default_loader']) {
            $services->alias('picasso.default_loader', 'picasso.loader.'.$config['default_loader']);
            $services->alias(ImageLoaderInterface::class, 'picasso.loader.'.$config['default_loader']);
        }

        // --- Registries ---

        $services->set('picasso.loader_registry', LoaderRegistry::class)
            ->args([tagged_locator('picasso.loader', 'key')]);
        $services->alias(LoaderRegistry::class, 'picasso.loader_registry');

        $services->set('picasso.transformer_registry', TransformerRegistry::class)
            ->args([tagged_locator('picasso.transformer', 'key')]);
        $services->alias(TransformerRegistry::class, 'picasso.transformer_registry');

        // --- Transformers ---

        $transformerConfig = $config['transformers'];
        $hasGlide = $transformerConfig['glide']['enabled'];
        $hasImgix = $transformerConfig['imgix']['enabled'];

        // Determine default transformer
        $defaultTransformer = $config['default_transformer'];
        if (null === $defaultTransformer) {
            if ($hasGlide && !$hasImgix) {
                $defaultTransformer = 'glide';
            } elseif ($hasImgix && !$hasGlide) {
                $defaultTransformer = 'imgix';
            } elseif ($hasGlide && $hasImgix) {
                throw new \LogicException('When both "glide" and "imgix" transformers are enabled, you must set "default_transformer" explicitly.');
            } else {
                throw new \LogicException('You must enable at least one transformer ("glide" and/or "imgix").');
            }
        }

        if ($hasGlide) {
            $glide = $transformerConfig['glide'];

            $services->set('picasso.url_encryption', UrlEncryption::class)
                ->args([$glide['sign_key']]);
            $services->alias(UrlEncryption::class, 'picasso.url_encryption');

            $services->set('picasso.transformer.glide', GlideTransformer::class)
                ->args([
                    service('router'),
                    service('picasso.url_encryption'),
                    $glide['sign_key'],
                    $glide['cache'] ?? '%kernel.project_dir%/var/glide-cache',
                    $glide['driver'],
                    $glide['max_image_size'],
                ])
                ->tag('picasso.transformer', ['key' => 'glide']);
        }

        if ($hasImgix) {
            $imgix = $transformerConfig['imgix'];
            $services->set('picasso.transformer.imgix', ImgixTransformer::class)
                ->args([
                    $imgix['domain'],
                    $imgix['sign_key'],
                    $imgix['use_https'],
                ])
                ->tag('picasso.transformer', ['key' => 'imgix']);
        }

        // Alias default transformer
        $services->alias('picasso.default_transformer', 'picasso.transformer.'.$defaultTransformer);
        $services->alias(ImageTransformerInterface::class, 'picasso.transformer.'.$defaultTransformer);

        // --- Controller ---

        $services->set('picasso.controller.image', ImageController::class)
            ->args([
                service('picasso.transformer_registry'),
                service('picasso.loader_registry'),
            ])
            ->tag('controller.service_arguments')
            ->public();

        // --- Pipeline ---

        $services->set('picasso.pipeline', ImagePipeline::class)
            ->args([
                tagged_locator('picasso.loader', 'key'),
                tagged_locator('picasso.transformer', 'key'),
                $config['default_loader'],
                $defaultTransformer,
            ]);
        $services->alias(ImagePipeline::class, 'picasso.pipeline');

        // --- Srcset Generator ---

        $services->set('picasso.srcset_generator', SrcsetGenerator::class)
            ->args([
                $config['device_sizes'],
                $config['image_sizes'],
                $config['formats'],
                $config['default_quality'],
            ]);
        $services->alias(SrcsetGenerator::class, 'picasso.srcset_generator');

        // --- Twig Extension ---

        $services->set('.picasso.twig_extension', PicassoExtension::class)
            ->args([
                service('picasso.pipeline'),
                $config['default_quality'],
                $config['default_fit'],
            ])
            ->tag('twig.extension');

        // --- Image Component ---

        $blurConfig = $config['placeholders']['blur'];

        $services->set('.picasso.image_component', ImageComponent::class)
            ->args([
                service('picasso.srcset_generator'),
                tagged_locator('picasso.loader', 'key'),
                tagged_locator('picasso.transformer', 'key'),
                service('picasso.metadata_guesser'),
                $config['default_loader'],
                $defaultTransformer,
                $config['formats'],
                $config['default_quality'],
                $config['default_fit'],
                $blurConfig['enabled'],
                $blurConfig['size'],
                $blurConfig['blur'],
                $blurConfig['quality'],
            ])
            ->tag('twig.component', [
                'key' => 'Picasso:Image',
                'template' => '@Picasso/components/Image.html.twig',
            ]);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('twig', [
            'paths' => [
                \dirname(__DIR__).'/templates' => 'Picasso',
            ],
        ]);
    }
}
