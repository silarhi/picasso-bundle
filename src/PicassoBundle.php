<?php

namespace Silarhi\PicassoBundle;

use League\Glide\Responses\SymfonyResponseFactory;
use League\Glide\ServerFactory;
use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Attribute\AsImageResolver;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Dto\BlurPlaceholderConfig;
use Silarhi\PicassoBundle\Loader\GlideLoader;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\ImgixLoader;
use Silarhi\PicassoBundle\Resolver\AssetMapperResolver;
use Silarhi\PicassoBundle\Resolver\FilesystemResolver;
use Silarhi\PicassoBundle\Resolver\FlysystemResolver;
use Silarhi\PicassoBundle\Resolver\ImageResolverInterface;
use Silarhi\PicassoBundle\Resolver\VichMappingHelper;
use Silarhi\PicassoBundle\Resolver\VichUploaderResolver;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Vich\UploaderBundle\Storage\StorageInterface as VichStorageInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

class PicassoBundle extends AbstractBundle
{
    private const ALLOWED_FORMATS = ['avif', 'webp', 'jpg', 'jpeg', 'pjpg', 'png', 'gif'];

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerAttributeForAutoconfiguration(
            AsImageResolver::class,
            static function (ChildDefinition $definition, AsImageResolver $attribute): void {
                $definition->addTag('picasso.resolver', ['key' => $attribute->name]);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsImageLoader::class,
            static function (ChildDefinition $definition, AsImageLoader $attribute): void {
                $definition->addTag('picasso.loader', ['key' => $attribute->name]);
            },
        );
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $allowedFormats = self::ALLOWED_FORMATS;

        $definition->rootNode()
            ->validate()
                ->ifTrue(fn (array $v) => empty($v['glide']) && empty($v['imgix']))
                ->thenInvalid('You must configure at least one loader ("glide" and/or "imgix").')
            ->end()
            ->children()
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
                ->scalarNode('default_resolver')
                    ->defaultValue('filesystem')
                ->end()
                ->scalarNode('default_loader')
                    ->defaultNull()
                    ->info('Which loader to use by default ("glide" or "imgix"). Auto-detected when only one is configured.')
                ->end()
                ->arrayNode('blur_placeholder')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('size')->defaultValue(10)->end()
                        ->integerNode('blur')->defaultValue(50)->end()
                        ->integerNode('quality')->defaultValue(30)->end()
                    ->end()
                ->end()
                ->arrayNode('filesystem')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_directory')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('glide')
                    ->children()
                        ->scalarNode('source')->isRequired()->end()
                        ->scalarNode('cache')->isRequired()->end()
                        ->scalarNode('driver')
                            ->defaultValue('gd')
                            ->validate()
                                ->ifNotInArray(['gd', 'imagick'])
                                ->thenInvalid('Driver must be "gd" or "imagick"')
                            ->end()
                        ->end()
                        ->scalarNode('sign_key')->isRequired()->end()
                        ->integerNode('max_image_size')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('imgix')
                    ->children()
                        ->scalarNode('domain')->isRequired()->info('Your imgix source domain (e.g. "my-source.imgix.net")')->end()
                        ->scalarNode('sign_key')->defaultNull()->info('Imgix secure URL token for signed URLs')->end()
                        ->booleanNode('use_https')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $hasGlide = !empty($config['glide']);
        $hasImgix = !empty($config['imgix']);

        // Determine default loader
        $defaultLoader = $config['default_loader'];
        if ($defaultLoader === null) {
            if ($hasGlide && !$hasImgix) {
                $defaultLoader = 'glide';
            } elseif ($hasImgix && !$hasGlide) {
                $defaultLoader = 'imgix';
            } elseif ($hasGlide && $hasImgix) {
                throw new \LogicException('When both "glide" and "imgix" loaders are configured, you must set "default_loader" explicitly.');
            }
        }

        // Register loaders
        if ($hasGlide) {
            $this->registerGlideServices($services, $config);
        }
        if ($hasImgix) {
            $this->registerImgixServices($services, $config);
        }

        // Alias the default loader
        if ($defaultLoader !== null) {
            $services->alias('picasso.default_loader', 'picasso.loader.'.$defaultLoader);
            $services->alias(ImageLoaderInterface::class, 'picasso.loader.'.$defaultLoader);
        }

        // Srcset Generator
        $services->set('picasso.srcset_generator', SrcsetGenerator::class)
            ->args([
                $config['device_sizes'],
                $config['image_sizes'],
                $config['formats'],
                $config['default_quality'],
            ]);
        $services->alias(SrcsetGenerator::class, 'picasso.srcset_generator');

        // Blur Placeholder Config
        $blurConfig = $config['blur_placeholder'];
        $services->set('picasso.blur_placeholder_config', BlurPlaceholderConfig::class)
            ->args([
                $blurConfig['enabled'],
                $blurConfig['size'],
                $blurConfig['blur'],
                $blurConfig['quality'],
            ]);

        // --- Resolvers ---

        // Filesystem Resolver
        $services->set('picasso.resolver.filesystem', FilesystemResolver::class)
            ->args([$config['filesystem']['base_directory'] ?? null])
            ->tag('picasso.resolver', ['key' => 'filesystem']);

        // Flysystem Resolver (always available, no dependencies)
        $services->set('picasso.resolver.flysystem', FlysystemResolver::class)
            ->tag('picasso.resolver', ['key' => 'flysystem']);

        // VichUploader Resolver (conditional)
        if (interface_exists(VichStorageInterface::class)) {
            $services->set('picasso.vich_mapping_helper', VichMappingHelper::class)
                ->args([service('Vich\\UploaderBundle\\Mapping\\PropertyMappingFactory')]);

            $services->set('picasso.resolver.vich_uploader', VichUploaderResolver::class)
                ->args([
                    service('Vich\\UploaderBundle\\Storage\\StorageInterface'),
                    service('picasso.vich_mapping_helper'),
                ])
                ->tag('picasso.resolver', ['key' => 'vich_uploader']);
        }

        // AssetMapper Resolver (conditional)
        if (interface_exists(AssetMapperInterface::class)) {
            $services->set('picasso.resolver.asset_mapper', AssetMapperResolver::class)
                ->args([service('asset_mapper')])
                ->tag('picasso.resolver', ['key' => 'asset_mapper']);
        }

        // Alias the default resolver
        $services->alias(ImageResolverInterface::class, 'picasso.resolver.'.$config['default_resolver']);

        // Twig Extension
        $services->set('picasso.twig_extension', PicassoExtension::class)
            ->args([
                tagged_locator('picasso.loader', 'key'),
                $defaultLoader,
            ])
            ->tag('twig.extension');

        // Image Component
        $services->set('picasso.image_component', ImageComponent::class)
            ->args([
                service('picasso.srcset_generator'),
                service('picasso.blur_placeholder_config'),
                tagged_locator('picasso.resolver', 'key'),
                tagged_locator('picasso.loader', 'key'),
                $config['default_resolver'],
                $defaultLoader,
                $config['formats'],
                $config['default_quality'],
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

    private function registerGlideServices(object $services, array $config): void
    {
        // Glide Server
        $glideConfig = [
            'source' => $config['glide']['source'],
            'cache' => $config['glide']['cache'],
            'driver' => $config['glide']['driver'],
            'response' => new SymfonyResponseFactory(),
        ];
        if ($config['glide']['max_image_size'] !== null) {
            $glideConfig['max_image_size'] = $config['glide']['max_image_size'];
        }

        $services->set('picasso.glide_server', \League\Glide\Server::class)
            ->factory([ServerFactory::class, 'create'])
            ->args([$glideConfig]);

        // Glide Loader — tagged as picasso.loader
        $services->set('picasso.loader.glide', GlideLoader::class)
            ->args([
                service('router'),
                $config['glide']['sign_key'],
            ])
            ->tag('picasso.loader', ['key' => 'glide']);
        $services->alias(GlideLoader::class, 'picasso.loader.glide');

        // Image Controller (Glide only — serves transformed images)
        $services->set('picasso.controller.image', ImageController::class)
            ->args([
                service('picasso.glide_server'),
                $config['glide']['sign_key'],
            ])
            ->tag('controller.service_arguments')
            ->public();
    }

    private function registerImgixServices(object $services, array $config): void
    {
        // Imgix Loader — tagged as picasso.loader
        $services->set('picasso.loader.imgix', ImgixLoader::class)
            ->args([
                $config['imgix']['domain'],
                $config['imgix']['sign_key'],
                $config['imgix']['use_https'],
            ])
            ->tag('picasso.loader', ['key' => 'imgix']);
        $services->alias(ImgixLoader::class, 'picasso.loader.imgix');
    }
}
