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

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Exception\InvalidRouteException;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerContextFactory;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;

class TransformerContextFactoryTest extends TestCase
{
    public function testContextNamesTheLoaderServingTheImage(): void
    {
        $context = $this->factory()->create(new Image(path: 'a.jpg', loader: 'uploads'), 'chain', 'glide');

        self::assertSame(['loader' => 'uploads', 'transformer' => 'glide'], $context);
    }

    public function testContextCarriesTheRoute(): void
    {
        $context = $this->factory()->create(new Image(path: 'a.jpg'), 'documents', 'glide', 'document_image', ['id' => 42]);

        self::assertSame([
            'loader' => 'documents',
            'transformer' => 'glide',
            'route' => 'document_image',
            'route_parameters' => ['id' => 42],
        ], $context);
    }

    public function testAPrivateLoaderRequiresARoute(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('Loader "documents" is private');

        $this->factory()->create(new Image(path: 'a.jpg'), 'documents', 'glide');
    }

    public function testAPrivateLoaderAChainDelegatesToRequiresARoute(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('Loader "documents" is private');

        $this->factory()->create(new Image(path: 'a.jpg', loader: 'documents'), 'chain', 'glide');
    }

    public function testOnlyLocalTransformersServeRoutes(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('Transformer "imgix" cannot serve images from route "document_image"');

        $this->factory()->create(new Image(path: 'a.jpg'), 'documents', 'imgix', 'document_image');
    }

    private function factory(): TransformerContextFactory
    {
        $transformers = self::createStub(ContainerInterface::class);
        $transformers->method('has')->willReturn(true);
        $transformers->method('get')->willReturnCallback(fn (string $name): ImageTransformerInterface => 'glide' === $name
            ? self::createStub(LocalTransformerInterface::class)
            : self::createStub(ImageTransformerInterface::class));

        return new TransformerContextFactory(
            new LoaderRegistry(self::createStub(ContainerInterface::class), privateLoaders: ['documents' => true]),
            new TransformerRegistry($transformers),
        );
    }
}
