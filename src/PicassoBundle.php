<?php

namespace Silarhi\PicassoBundle;

use League\Glide\Responses\SymfonyResponseFactory;
use League\Glide\ServerFactory;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Loader\FileLoader;
use Silarhi\PicassoBundle\Loader\VichUploaderLoader;
use Silarhi\PicassoBundle\Service\BlurHashGenerator;
use Silarhi\PicassoBundle\Service\GlideBlurHashGenerator;
use Silarhi\PicassoBundle\Service\SrcsetGenerator;
use Silarhi\PicassoBundle\Twig\Component\ImageComponent;
use Silarhi\PicassoBundle\Twig\Extension\PicassoExtension;
use Silarhi\PicassoBundle\Url\GlideImageUrlGenerator;
use Silarhi\PicassoBundle\Url\ImageUrlGeneratorInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Vich\UploaderBundle\Templating\Helper\UploaderHelperInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

class PicassoBundle extends AbstractBundle
{
    private const ALLOWED_FORMATS = ['avif', 'webp', 'jpg', 'jpeg', 'pjpg', 'png', 'gif'];

    public function configure(DefinitionConfigurator $definition): void
    {
        $allowedFormats = self::ALLOWED_FORMATS;

        $definition->rootNode()
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
                    ->isRequired()
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
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

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

        // Image URL Generator (Glide implementation)
        $services->set('picasso.url_generator', GlideImageUrlGenerator::class)
            ->args([
                service('router'),
                $config['glide']['sign_key'],
            ]);
        $services->alias(ImageUrlGeneratorInterface::class, 'picasso.url_generator');
        $services->alias(GlideImageUrlGenerator::class, 'picasso.url_generator');

        // Srcset Generator
        $services->set('picasso.srcset_generator', SrcsetGenerator::class)
            ->args([
                service('picasso.url_generator'),
                $config['device_sizes'],
                $config['image_sizes'],
                $config['formats'],
                $config['default_quality'],
            ]);
        $services->alias(SrcsetGenerator::class, 'picasso.srcset_generator');

        // BlurHash Generator (Glide implementation)
        $services->set('picasso.blur_hash_generator', GlideBlurHashGenerator::class)
            ->args([
                service('picasso.glide_server'),
                $config['blur_placeholder'],
            ]);
        $services->alias(BlurHashGenerator::class, 'picasso.blur_hash_generator');
        $services->alias(GlideBlurHashGenerator::class, 'picasso.blur_hash_generator');

        // File Loader — base_directory falls back to Glide source
        $fileLoaderBaseDir = $config['file_loader']['base_directory'] ?? $config['glide']['source'];
        $services->set('picasso.loader.file', FileLoader::class)
            ->args([$fileLoaderBaseDir])
            ->tag('picasso.loader', ['key' => 'file']);

        // VichUploader Loader (conditional)
        if (interface_exists(UploaderHelperInterface::class)) {
            $services->set('picasso.loader.vich_uploader', VichUploaderLoader::class)
                ->args([service('Vich\\UploaderBundle\\Templating\\Helper\\UploaderHelperInterface')])
                ->tag('picasso.loader', ['key' => 'vich_uploader']);
        }

        // Twig Extension
        $services->set('picasso.twig_extension', PicassoExtension::class)
            ->args([service('picasso.url_generator')])
            ->tag('twig.extension');

        // Image Controller
        $services->set('picasso.controller.image', ImageController::class)
            ->args([
                service('picasso.glide_server'),
                $config['glide']['sign_key'],
            ])
            ->tag('controller.service_arguments')
            ->public();

        // Image Component
        $services->set('picasso.image_component', ImageComponent::class)
            ->args([
                service('picasso.srcset_generator'),
                service('picasso.blur_hash_generator'),
                tagged_locator('picasso.loader', 'key'),
                $config['default_loader'],
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
}
