<?php

namespace Silarhi\PicassoBundle;

use League\Glide\Responses\SymfonyResponseFactory;
use League\Glide\ServerFactory;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Dto\BlurPlaceholderConfig;
use Silarhi\PicassoBundle\Loader\FileLoader;
use Silarhi\PicassoBundle\Loader\FlysystemLoader;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;
use Silarhi\PicassoBundle\Url\GlideImageUrlGenerator;
use Silarhi\PicassoBundle\Url\ImageUrlGeneratorInterface;
use Silarhi\PicassoBundle\Url\ImgixImageUrlGenerator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Vich\UploaderBundle\Storage\StorageInterface as VichStorageInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

class PicassoBundle extends AbstractBundle
{
    private const ALLOWED_FORMATS = ['avif', 'webp', 'jpg', 'jpeg', 'pjpg', 'png', 'gif'];

    public function configure(DefinitionConfigurator $definition): void
    {
        $allowedFormats = self::ALLOWED_FORMATS;

        $definition->rootNode()
            ->validate()
                ->ifTrue(fn (array $v) => empty($v['glide']) && empty($v['imgix']))
                ->thenInvalid('You must configure at least one provider ("glide" and/or "imgix").')
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
                ->scalarNode('default_loader')
                    ->defaultValue('file')
                ->end()
                ->scalarNode('default_provider')
                    ->defaultNull()
                    ->info('Which provider to use by default ("glide" or "imgix"). Auto-detected when only one is configured.')
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
                ->arrayNode('file_loader')
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

        // Determine default provider
        $defaultProvider = $config['default_provider'];
        if ($defaultProvider === null) {
            if ($hasGlide && !$hasImgix) {
                $defaultProvider = 'glide';
            } elseif ($hasImgix && !$hasGlide) {
                $defaultProvider = 'imgix';
            } elseif ($hasGlide && $hasImgix) {
                throw new \LogicException('When both "glide" and "imgix" providers are configured, you must set "default_provider" explicitly.');
            }
        }

        // Register providers
        if ($hasGlide) {
            $this->registerGlideServices($services, $config);
        }
        if ($hasImgix) {
            $this->registerImgixServices($services, $config);
        }

        // Alias the default provider for backward compatibility
        if ($defaultProvider !== null) {
            $services->alias('picasso.url_generator', 'picasso.provider.'.$defaultProvider);
            $services->alias(ImageUrlGeneratorInterface::class, 'picasso.provider.'.$defaultProvider);
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

        // --- Loaders ---

        // File Loader
        $fileLoaderBaseDir = $config['file_loader']['base_directory'] ?? null;
        if ($fileLoaderBaseDir !== null) {
            $services->set('picasso.loader.file', FileLoader::class)
                ->args([$fileLoaderBaseDir])
                ->tag('picasso.loader', ['key' => 'file']);
        }

        // Flysystem Loader (always available, no dependencies)
        $services->set('picasso.loader.flysystem', FlysystemLoader::class)
            ->tag('picasso.loader', ['key' => 'flysystem']);

        // VichUploader Loader (conditional)
        if (interface_exists(VichStorageInterface::class)) {
            $services->set('picasso.loader.vich_uploader', VichUploaderLoader::class)
                ->args([service('Vich\\UploaderBundle\\Storage\\StorageInterface')])
                ->tag('picasso.loader', ['key' => 'vich_uploader']);
        }

        // Twig Extension
        $services->set('picasso.twig_extension', PicassoExtension::class)
            ->args([
                tagged_locator('picasso.provider', 'key'),
                $defaultProvider,
            ])
            ->tag('twig.extension');

        // Image Component
        $services->set('picasso.image_component', ImageComponent::class)
            ->args([
                service('picasso.srcset_generator'),
                service('picasso.blur_placeholder_config'),
                tagged_locator('picasso.loader', 'key'),
                tagged_locator('picasso.provider', 'key'),
                $config['default_loader'],
                $defaultProvider,
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

        // Image URL Generator (Glide) — tagged as provider
        $services->set('picasso.provider.glide', GlideImageUrlGenerator::class)
            ->args([
                service('router'),
                $config['glide']['sign_key'],
            ])
            ->tag('picasso.provider', ['key' => 'glide']);
        $services->alias(GlideImageUrlGenerator::class, 'picasso.provider.glide');

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
        // Image URL Generator (imgix) — tagged as provider
        $services->set('picasso.provider.imgix', ImgixImageUrlGenerator::class)
            ->args([
                $config['imgix']['domain'],
                $config['imgix']['sign_key'],
                $config['imgix']['use_https'],
            ])
            ->tag('picasso.provider', ['key' => 'imgix']);
        $services->alias(ImgixImageUrlGenerator::class, 'picasso.provider.imgix');
    }
}
