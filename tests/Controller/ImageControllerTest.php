<?php

namespace Silarhi\PicassoBundle\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        $transformers = $this->createMock(ContainerInterface::class);
        $transformers->method('has')->with('glide')->willReturn(true);
        $transformers->method('get')->with('glide')->willReturn($transformer);

        $loaders = $this->createMock(ContainerInterface::class);
        $loaders->method('has')->with('filesystem')->willReturn(true);
        $loaders->method('get')->with('filesystem')->willReturn($loader);

        $controller = new ImageController($transformers, $loaders);
        $response = $controller->__invoke('glide', 'filesystem', 'photo.jpg', $request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvokeThrowsNotFoundForUnknownTransformer(): void
    {
        $transformers = $this->createMock(ContainerInterface::class);
        $transformers->method('has')->with('unknown')->willReturn(false);

        $loaders = $this->createMock(ContainerInterface::class);

        $controller = new ImageController($transformers, $loaders);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Transformer "unknown" not found.');
        $controller->__invoke('unknown', 'filesystem', 'photo.jpg', new Request());
    }

    public function testInvokeThrowsNotFoundForNonLocalTransformer(): void
    {
        $transformer = $this->createMock(ImageTransformerInterface::class);

        $transformers = $this->createMock(ContainerInterface::class);
        $transformers->method('has')->with('imgix')->willReturn(true);
        $transformers->method('get')->with('imgix')->willReturn($transformer);

        $loaders = $this->createMock(ContainerInterface::class);

        $controller = new ImageController($transformers, $loaders);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('does not support serving');
        $controller->__invoke('imgix', 'filesystem', 'photo.jpg', new Request());
    }

    public function testInvokeThrowsNotFoundForUnknownLoader(): void
    {
        $transformer = $this->createMock(LocalTransformerInterface::class);

        $transformers = $this->createMock(ContainerInterface::class);
        $transformers->method('has')->with('glide')->willReturn(true);
        $transformers->method('get')->with('glide')->willReturn($transformer);

        $loaders = $this->createMock(ContainerInterface::class);
        $loaders->method('has')->with('unknown')->willReturn(false);

        $controller = new ImageController($transformers, $loaders);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Loader "unknown" not found.');
        $controller->__invoke('glide', 'unknown', 'photo.jpg', new Request());
    }

    public function testInvokeThrowsNotFoundForNonServableLoader(): void
    {
        $transformer = $this->createMock(LocalTransformerInterface::class);
        $loader = $this->createMock(ImageLoaderInterface::class);

        $transformers = $this->createMock(ContainerInterface::class);
        $transformers->method('has')->with('glide')->willReturn(true);
        $transformers->method('get')->with('glide')->willReturn($transformer);

        $loaders = $this->createMock(ContainerInterface::class);
        $loaders->method('has')->with('remote')->willReturn(true);
        $loaders->method('get')->with('remote')->willReturn($loader);

        $controller = new ImageController($transformers, $loaders);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('does not support serving');
        $controller->__invoke('glide', 'remote', 'photo.jpg', new Request());
    }
}
