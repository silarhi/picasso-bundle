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

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;
use Silarhi\PicassoBundle\Exception\UndecodableImageException;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
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

        $controller = new ImageController($transformerRegistry, $loaderRegistry, self::cacheControl(), new UrlAliases([], []));
        $response = $controller->__invoke('glide', 'filesystem', 'photo.jpg', $request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvokeResolvesUrlAliasesToNames(): void
    {
        $request = new Request();
        $loader = $this->createMock(ServableLoaderInterface::class);
        $transformer = $this->createMock(LocalTransformerInterface::class);
        $transformer->expects(self::once())
            ->method('serve')
            ->with($loader, 'photo.jpg', $request, ['transformer' => 'glide', 'loader' => 'product_image'])
            ->willReturn(new Response('image-data'));

        $controller = new ImageController(
            $this->createRegistry(TransformerRegistry::class, 'glide', $transformer),
            $this->createRegistry(LoaderRegistry::class, 'product_image', $loader),
            self::cacheControl(),
            new UrlAliases(['product_image' => 'p'], ['glide' => 'g']),
        );

        self::assertSame(200, $controller->__invoke('g', 'p', 'photo.jpg', $request)->getStatusCode());
    }

    public function testInvokeThrowsNotFoundForPrivateLoader(): void
    {
        $transformer = $this->createMock(LocalTransformerInterface::class);
        $transformer->expects(self::never())->method('serve');

        $loaderContainer = self::createStub(ContainerInterface::class);
        $loaderContainer->method('has')->willReturn(true);
        $loaderRegistry = new LoaderRegistry($loaderContainer, privateLoaders: ['documents' => true]);

        $controller = new ImageController(
            $this->createRegistry(TransformerRegistry::class, 'glide', $transformer),
            $loaderRegistry,
            self::cacheControl(),
            new UrlAliases(['documents' => 'd'], []),
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Loader "d" is private.');
        $controller->__invoke('glide', 'd', 'photo.jpg', new Request());
    }

    public function testInvokeThrowsNotFoundForUnknownTransformer(): void
    {
        $transformerContainer = $this->createMock(ContainerInterface::class);
        $transformerContainer->expects(self::any())->method('has')->with('unknown')->willReturn(false);
        $transformerRegistry = new TransformerRegistry($transformerContainer);

        $loaderContainer = $this->createMock(ContainerInterface::class);
        $loaderRegistry = new LoaderRegistry($loaderContainer);

        $controller = new ImageController($transformerRegistry, $loaderRegistry, self::cacheControl(), new UrlAliases([], []));

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

        $controller = new ImageController($transformerRegistry, $loaderRegistry, self::cacheControl(), new UrlAliases([], []));

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

        $controller = new ImageController($transformerRegistry, $loaderRegistry, self::cacheControl(), new UrlAliases([], []));

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

        $controller = new ImageController($transformerRegistry, $loaderRegistry, self::cacheControl(), new UrlAliases([], []));

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

    public function testServedImageGetsTheConfiguredCacheHeaders(): void
    {
        $controller = $this->createControllerServing($this->glideLikeResponse(), maxAge: 3600, immutable: true);

        $response = $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());

        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
        self::assertFalse($response->headers->has('Expires'), 'A transformer Expires must not contradict the configured max-age.');
    }

    public function testServedImageIsNotMarkedImmutableWhenDisabled(): void
    {
        $controller = $this->createControllerServing($this->glideLikeResponse(), maxAge: 600, immutable: false);

        $response = $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());

        self::assertSame('600', $response->headers->getCacheControlDirective('max-age'));
        self::assertFalse($response->headers->hasCacheControlDirective('immutable'));
    }

    public function testServedImageKeepsTheTransformerHeadersWithoutMaxAge(): void
    {
        $controller = $this->createControllerServing($this->glideLikeResponse(), maxAge: null, immutable: true);

        $response = $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());

        self::assertSame('31536000', $response->headers->getCacheControlDirective('max-age'));
        self::assertFalse($response->headers->hasCacheControlDirective('immutable'));
        self::assertTrue($response->headers->has('Expires'));
    }

    public function testNotModifiedResponseGetsTheConfiguredCacheHeaders(): void
    {
        $controller = $this->createControllerServing(new Response('', Response::HTTP_NOT_MODIFIED), maxAge: 3600, immutable: true);

        $response = $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());

        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
    }

    public function testRedirectKeepsTheTransformerHeaders(): void
    {
        $redirect = new RedirectResponse('/image/glide/filesystem/photo.jpg/w_10.jpg', Response::HTTP_MOVED_PERMANENTLY);
        $redirect->setPublic();
        $redirect->setMaxAge(2592000);
        $controller = $this->createControllerServing($redirect, maxAge: 3600, immutable: true);

        $response = $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());

        self::assertSame('2592000', $response->headers->getCacheControlDirective('max-age'));
        self::assertFalse($response->headers->hasCacheControlDirective('immutable'));
    }

    public function testNotFoundIsCacheableForTheErrorMaxAge(): void
    {
        $controller = $this->createControllerThrowing(new ImageNotFoundException('Image not found.'), 60);

        try {
            $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());
            self::fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            self::assertSame(['Cache-Control' => 'public, max-age=60'], $e->getHeaders());
        }
    }

    public function testNotFoundForAnUnknownTransformerOrLoaderIsCacheableForTheErrorMaxAge(): void
    {
        $missing = self::createStub(ContainerInterface::class);
        $missing->method('has')->willReturn(false);
        $transformer = self::createStub(LocalTransformerInterface::class);

        foreach ([
            'transformer' => new ImageController(new TransformerRegistry($missing), new LoaderRegistry($missing), self::cacheControl(errorMaxAge: 0), new UrlAliases([], [])),
            'loader' => new ImageController($this->createRegistry(TransformerRegistry::class, 'glide', $transformer), new LoaderRegistry($missing), self::cacheControl(errorMaxAge: 0), new UrlAliases([], [])),
        ] as $case => $controller) {
            try {
                $controller->__invoke('glide', 'unknown', 'photo.jpg', new Request());
                self::fail('Expected a NotFoundHttpException.');
            } catch (NotFoundHttpException $e) {
                self::assertSame(['Cache-Control' => 'public, max-age=0'], $e->getHeaders(), $case);
            }
        }
    }

    public function testNotFoundStaysUncacheableWithoutErrorMaxAge(): void
    {
        $controller = $this->createControllerThrowing(new ImageNotFoundException('Image not found.'));

        try {
            $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());
            self::fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            self::assertSame([], $e->getHeaders());
        }
    }

    public function testAnUnavailableSourceIsAnUncacheable503(): void
    {
        $unavailable = new ImageSourceUnavailableException('Image "photo.jpg" could not be read: its storage is unavailable.');
        // A CDN keeping the answer as long as a 404 would go on failing the image after the outage
        $controller = $this->createControllerThrowing($unavailable, 60);

        try {
            $controller->__invoke('glide', 'filesystem', 'photo.jpg', new Request());
            self::fail('Expected a ServiceUnavailableHttpException.');
        } catch (ServiceUnavailableHttpException $e) {
            self::assertSame(503, $e->getStatusCode());
            self::assertSame($unavailable, $e->getPrevious());
            self::assertSame(['Cache-Control' => 'no-store', 'Retry-After' => 30], $e->getHeaders());
        }
    }

    /**
     * Headers as Glide's Symfony response factory sets them.
     */
    private function glideLikeResponse(): Response
    {
        $response = new Response('image-data', Response::HTTP_OK, ['Content-Type' => 'image/webp']);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setExpires(new DateTimeImmutable('+1 year'));

        return $response;
    }

    private function createControllerServing(Response $response, ?int $maxAge, bool $immutable): ImageController
    {
        $loader = self::createStub(ServableLoaderInterface::class);
        $transformer = self::createStub(LocalTransformerInterface::class);
        $transformer->method('serve')->willReturn($response);

        return new ImageController(
            $this->createRegistry(TransformerRegistry::class, 'glide', $transformer),
            $this->createRegistry(LoaderRegistry::class, 'filesystem', $loader),
            self::cacheControl($maxAge, $immutable),
            new UrlAliases([], []),
        );
    }

    private function createControllerThrowing(Throwable $exception, ?int $errorMaxAge = null): ImageController
    {
        $loader = self::createStub(ServableLoaderInterface::class);
        $transformer = self::createStub(LocalTransformerInterface::class);
        $transformer->method('serve')->willThrowException($exception);

        return new ImageController(
            $this->createRegistry(TransformerRegistry::class, 'glide', $transformer),
            $this->createRegistry(LoaderRegistry::class, 'filesystem', $loader),
            self::cacheControl(errorMaxAge: $errorMaxAge),
            new UrlAliases([], []),
        );
    }

    /**
     * @return CacheControlConfig
     */
    private static function cacheControl(?int $maxAge = null, bool $immutable = false, ?int $errorMaxAge = null): array
    {
        return ['max_age' => $maxAge, 'immutable' => $immutable, 'error_max_age' => $errorMaxAge];
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
