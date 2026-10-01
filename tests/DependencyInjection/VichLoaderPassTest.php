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

namespace Silarhi\PicassoBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\DependencyInjection\VichLoaderPass;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Service\LegacyMetadataResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class VichLoaderPassTest extends TestCase
{
    private const MAPPINGS = [
        'product_image' => ['upload_destination' => '%kernel.project_dir%/public/products'],
        'user_avatar' => ['upload_destination' => 'avatars.storage'],
    ];

    public function testExplicitMappingIsBound(): void
    {
        $definition = $this->process('avatars', 'user_avatar', self::MAPPINGS);

        self::assertSame('user_avatar', $definition->getArgument(2));
        self::assertSame('avatars.storage', $definition->getArgument(3));
    }

    public function testLoaderNamedAfterAMappingServesIt(): void
    {
        $definition = $this->process('product_image', null, self::MAPPINGS);

        self::assertSame('product_image', $definition->getArgument(2));
        self::assertSame('%kernel.project_dir%/public/products', $definition->getArgument(3));
    }

    public function testTheOnlyMappingIsServedByDefault(): void
    {
        $definition = $this->process('vich', null, ['user_avatar' => self::MAPPINGS['user_avatar']]);

        self::assertSame('user_avatar', $definition->getArgument(2));
        self::assertSame('avatars.storage', $definition->getArgument(3));
    }

    public function testUnknownExplicitMappingListsTheAvailableOnes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "products" serves the VichUploader mapping "product", which does not exist. Available mappings: "product_image", "user_avatar".');

        $this->process('products', 'product', self::MAPPINGS);
    }

    public function testAmbiguousLoaderAsksForAMapping(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "vich" does not say which VichUploader mapping it serves. Set its "mapping" option, or name the loader after the mapping. Available mappings: "product_image", "user_avatar". A vich loader serves a single mapping: declare one loader per mapping.');

        $this->process('vich', null, self::MAPPINGS);
    }

    public function testNoMappingConfigured(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "vich" is a vich loader, but VichUploader has no mappings configured.');

        $this->process('vich', null, []);
    }

    public function testVichUploaderBundleMustBeRegistered(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "vich" is a vich loader, but VichUploaderBundle is not registered in your kernel.');

        $this->process('vich', null, null);
    }

    public function testMappingWithoutUploadDestination(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "vich": the VichUploader mapping "product_image" has no "upload_destination".');

        $this->process('vich', null, ['product_image' => ['uri_prefix' => '/products']]);
    }

    public function testUploadDestinationsLead1xUrlsToTheirLoader(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('vich_uploader.mappings', self::MAPPINGS);
        // A filesystem loader declared first keeps a root it shares with a vich loader
        $resolver = $container->setDefinition(LegacyMetadataResolver::SERVICE, new Definition(LegacyMetadataResolver::class, ['key', ['%kernel.project_dir%/public/products' => 'products_fs'], '/app']));
        foreach (['product_image', 'user_avatar'] as $loader) {
            $container->setDefinition('picasso.loader.' . $loader, (new Definition())
                ->setArguments([null, null, '', '', null])
                ->addTag(VichLoaderPass::TAG, ['loader' => $loader, 'mapping' => null]));
        }

        (new VichLoaderPass())->process($container);

        self::assertSame(['%kernel.project_dir%/public/products' => 'products_fs', 'avatars.storage' => 'user_avatar'], $resolver->getArgument(1));
    }

    public function testNothingToDoWithoutVichLoaders(): void
    {
        $container = new ContainerBuilder();

        (new VichLoaderPass())->process($container);

        self::assertFalse($container->hasParameter('vich_uploader.mappings'));
    }

    /**
     * @param array<string, array<string, string>>|null $mappings
     */
    private function process(string $loader, ?string $mapping, ?array $mappings): Definition
    {
        $container = new ContainerBuilder();
        if (null !== $mappings) {
            $container->setParameter('vich_uploader.mappings', $mappings);
        }

        $definition = (new Definition())
            ->setArguments([null, null, '', '', null])
            ->addTag(VichLoaderPass::TAG, ['loader' => $loader, 'mapping' => $mapping]);
        $container->setDefinition('picasso.loader.' . $loader, $definition);

        (new VichLoaderPass())->process($container);

        return $definition;
    }
}
