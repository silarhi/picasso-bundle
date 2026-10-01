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
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\UndecodableImageException;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

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
            ->with($loader, 'photo.jpg', $request, ['transformer' => 'glide', 'loader' => 'filesystem'])
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
        $transformerContainer->expects(self::any())->method('has')->with('unknown')->willReturn(false);
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
        $loaderContainer->expects(self::any())->method('has')->with('unknown')->willReturn(false);
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

    public function testInvokeThrowsNotFoundForMissingImage(): void
    {
        $exception = new ImageNotFoundException('Image not found.');
        $controller = $this->createControllerThrowing($exception);

        try {
            $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());
            self::fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            self::assertSame($exception, $e->getPrevious());
        }
    }

    public function testInvokeThrowsNotFoundForUndecodableImage(): void
    {
        $exception = new UndecodableImageException('Source image could not be decoded.');
        $controller = $this->createControllerThrowing($exception);

        try {
            $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());
            self::fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            // Consumers tell the two 404s apart through the previous exception:
            // a missing source may be recovered from, a broken one may not.
            self::assertSame($exception, $e->getPrevious());
            self::assertNotInstanceOf(ImageNotFoundException::class, $e->getPrevious());
        }
    }

    public function testNotFoundIsCacheableForTheTransformerErrorMaxAge(): void
    {
        $controller = $this->createControllerThrowing(new ImageNotFoundException('Image not found.'), ['glide' => 60]);

        try {
            $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());
            self::fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            self::assertSame(['Cache-Control' => 'public, max-age=60'], $e->getHeaders());
        }
    }

    public function testNotFoundForAnUnknownLoaderIsCacheableForTheTransformerErrorMaxAge(): void
    {
        $transformer = self::createStub(LocalTransformerInterface::class);
        $loaderContainer = self::createStub(ContainerInterface::class);
        $loaderContainer->method('has')->willReturn(false);
        $controller = new ImageController(
            $this->createRegistry(TransformerRegistry::class, 'glide', $transformer),
            new LoaderRegistry($loaderContainer),
            null,
            ['glide' => 0],
        );

        try {
            $controller->__invoke('glide', 'unknown', 'photo.jpg', new Request());
            self::fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            self::assertSame(['Cache-Control' => 'public, max-age=0'], $e->getHeaders());
        }
    }

    public function testNotFoundStaysUncacheableWithoutErrorMaxAge(): void
    {
        $controller = $this->createControllerThrowing(new ImageNotFoundException('Image not found.'), ['other' => 60]);

        try {
            $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());
            self::fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            self::assertSame([], $e->getHeaders());
        }
    }

    /**
     * @param array<string, int> $errorMaxAges
     */
    private function createControllerThrowing(Throwable $exception, array $errorMaxAges = []): ImageController
    {
        $loader = self::createStub(ServableLoaderInterface::class);
        $transformer = self::createStub(LocalTransformerInterface::class);
        $transformer->method('serve')->willThrowException($exception);

        return new ImageController(
            $this->createRegistry(TransformerRegistry::class, 'glide', $transformer),
            $this->createRegistry(LoaderRegistry::class, 'filesystem', $loader),
            null,
            $errorMaxAges,
        );
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
        $container->expects(self::any())->method('has')->with($name)->willReturn(true);
        $container->expects(self::any())->method('get')->with($name)->willReturn($service);

        return new $registryClass($container);
    }
}
