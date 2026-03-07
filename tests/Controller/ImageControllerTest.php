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

namespace Silarhi\PicassoBundle\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ImageControllerTest extends TestCase
{
    public function testInvokeServesImage(): void
    {
        $request = new Request();
        $expectedResponse = new Response('image-data', 200, ['Content-Type' => 'image/webp']);

        $loader = $this->createMock(ServableLoaderInterface::class);
        $transformer = $this->createMock(LocalTransformerInterface::class);
        $transformer->expects(self::once())
            ->method('serve')
            ->with($loader, 'photo.jpg', $request)
            ->willReturn($expectedResponse);

        $transformerRegistry = $this->createRegistry(TransformerRegistry::class, 'glide', $transformer);
        $loaderRegistry = $this->createRegistry(LoaderRegistry::class, 'filesystem', $loader);

        $controller = new ImageController($transformerRegistry, $loaderRegistry);
        $response = $controller->__invoke('glide', 'filesystem', 'photo.jpg', $request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvokeThrowsNotFoundForUnknownTransformer(): void
    {
        $transformerContainer = $this->createMock(ContainerInterface::class);
        $transformerContainer->method('has')->with('unknown')->willReturn(false);
        $transformerRegistry = new TransformerRegistry($transformerContainer);

        $loaderContainer = $this->createMock(ContainerInterface::class);
        $loaderRegistry = new LoaderRegistry($loaderContainer);

        $controller = new ImageController($transformerRegistry, $loaderRegistry);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Transformer "unknown" not found.');
        $controller->__invoke('unknown', 'filesystem', 'photo.jpg', new Request());
    }

    public function testInvokeThrowsNotFoundForNonLocalTransformer(): void
    {
        $transformer = $this->createMock(ImageTransformerInterface::class);
        $transformerRegistry = $this->createRegistry(TransformerRegistry::class, 'imgix', $transformer);

        $loaderContainer = $this->createMock(ContainerInterface::class);
        $loaderRegistry = new LoaderRegistry($loaderContainer);

        $controller = new ImageController($transformerRegistry, $loaderRegistry);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('does not support serving');
        $controller->__invoke('imgix', 'filesystem', 'photo.jpg', new Request());
    }

    public function testInvokeThrowsNotFoundForUnknownLoader(): void
    {
        $transformer = $this->createMock(LocalTransformerInterface::class);
        $transformerRegistry = $this->createRegistry(TransformerRegistry::class, 'glide', $transformer);

        $loaderContainer = $this->createMock(ContainerInterface::class);
        $loaderContainer->method('has')->with('unknown')->willReturn(false);
        $loaderRegistry = new LoaderRegistry($loaderContainer);

        $controller = new ImageController($transformerRegistry, $loaderRegistry);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Loader "unknown" not found.');
        $controller->__invoke('glide', 'unknown', 'photo.jpg', new Request());
    }

    public function testInvokeThrowsNotFoundForNonServableLoader(): void
    {
        $transformer = $this->createMock(LocalTransformerInterface::class);
        $loader = $this->createMock(ImageLoaderInterface::class);

        $transformerRegistry = $this->createRegistry(TransformerRegistry::class, 'glide', $transformer);
        $loaderRegistry = $this->createRegistry(LoaderRegistry::class, 'remote', $loader);

        $controller = new ImageController($transformerRegistry, $loaderRegistry);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('does not support serving');
        $controller->__invoke('glide', 'remote', 'photo.jpg', new Request());
    }

    public function testCachedThrowsNotFoundWhenPublicCacheDisabled(): void
    {
        $loader = $this->createMock(ServableLoaderInterface::class);
        $transformer = new GlideTransformer(
            $this->createMock(UrlGeneratorInterface::class),
            new UrlEncryption('test-key'),
            'test-key',
            '/tmp/cache',
        );

        $transformerRegistry = $this->createRegistry(TransformerRegistry::class, 'glide', $transformer);
        $loaderRegistry = $this->createRegistry(LoaderRegistry::class, 'filesystem', $loader);

        $controller = new ImageController($transformerRegistry, $loaderRegistry);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('does not support public cache');
        $controller->cached('glide', 'filesystem', 'photos/hero.jpg/params.webp');
    }

    public function testCachedThrowsNotFoundForInvalidPath(): void
    {
        $loader = $this->createMock(ServableLoaderInterface::class);
        $transformer = new GlideTransformer(
            $this->createMock(UrlGeneratorInterface::class),
            new UrlEncryption('test-key'),
            'test-key',
            '/tmp/cache',
            'gd',
            null,
            ['enabled' => true, 'path' => '/tmp/public-cache'],
        );

        $transformerRegistry = $this->createRegistry(TransformerRegistry::class, 'glide', $transformer);
        $loaderRegistry = $this->createRegistry(LoaderRegistry::class, 'filesystem', $loader);

        $controller = new ImageController($transformerRegistry, $loaderRegistry);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Invalid cached image path');
        $controller->cached('glide', 'filesystem', 'no-slash-in-path');
    }

    public function testCachedThrowsNotFoundForNonGlideTransformer(): void
    {
        $loader = $this->createMock(ServableLoaderInterface::class);
        $transformer = $this->createMock(LocalTransformerInterface::class);

        $transformerRegistry = $this->createRegistry(TransformerRegistry::class, 'custom', $transformer);
        $loaderRegistry = $this->createRegistry(LoaderRegistry::class, 'filesystem', $loader);

        $controller = new ImageController($transformerRegistry, $loaderRegistry);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('does not support public cache');
        $controller->cached('custom', 'filesystem', 'photos/hero.jpg/params.webp');
    }

    /**
     * @template T of LoaderRegistry|TransformerRegistry
     *
     * @param class-string<T> $registryClass
     *
     * @return T
     */
    private function createRegistry(string $registryClass, string $name, object $service): LoaderRegistry|TransformerRegistry
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with($name)->willReturn(true);
        $container->method('get')->with($name)->willReturn($service);

        return new $registryClass($container);
    }
}
