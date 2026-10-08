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
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Exception\UndecodableImageException;
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Silarhi\PicassoBundle\Service\ImageServer;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\ImageTransformerInterface;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class ImageServerTest extends TestCase
{
    public function testServesTheImageOfTheReferenceThroughTheLoaderServingIt(): void
    {
        $request = new Request();
        $reference = new ImageReference(context: ['entity' => new stdClass()]);
        $member = self::createStub(ServableLoaderInterface::class);
        $chain = $this->createMock(ImageLoaderInterface::class);
        $chain->expects(self::once())->method('load')->with($reference)->willReturn(new Image(path: 'docs/42.jpg', loader: 'uploads'));

        $transformer = $this->createMock(LocalTransformerInterface::class);
        $transformer->expects(self::once())
            ->method('serve')
            ->with($member, 'docs/42.jpg', $request, ['transformer' => 'private_glide', 'loader' => 'uploads'])
            ->willReturn(new Response('image', 200, ['Cache-Control' => 'public, max-age=31536000', 'Expires' => 'Wed, 01 Jan 2031 00:00:00 GMT']));

        $response = $this->server(['documents' => $chain, 'uploads' => $member], $transformer, ['documents' => 'private_glide'])
            ->serve($request, $reference, 'documents');

        self::assertSame('image', $response->getContent());
        self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('Expires'));
    }

    public function testAPathIsAReference(): void
    {
        $loader = $this->createMock(ServableLoaderInterface::class);
        $loader->expects(self::once())
            ->method('load')
            ->with(self::callback(static fn (ImageReference $r): bool => 'photo.jpg' === $r->path))
            ->willReturn(new Image(path: 'photo.jpg'));
        $transformer = self::createStub(LocalTransformerInterface::class);
        $transformer->method('serve')->willReturn(new Response());

        self::assertSame(200, $this->server(['documents' => $loader], $transformer)->serve(new Request(), 'photo.jpg', 'documents')->getStatusCode());
    }

    public function testAnImageWithoutPathIsNotFound(): void
    {
        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('load')->willReturn(new Image(path: ''));

        $this->expectException(NotFoundHttpException::class);
        $this->server(['documents' => $loader], self::createStub(LocalTransformerInterface::class))->serve(new Request(), 'x', 'documents');
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function notFoundExceptions(): iterable
    {
        yield 'missing image or bad signature' => [new ImageNotFoundException('Invalid image signature.')];
        yield 'undecodable image' => [new UndecodableImageException('Source image could not be decoded.')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notFoundExceptions')]
    public function testUnservableImagesAreNotFound(Throwable $exception): void
    {
        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('load')->willReturn(new Image(path: 'photo.jpg'));
        $transformer = self::createStub(LocalTransformerInterface::class);
        $transformer->method('serve')->willThrowException($exception);

        try {
            $this->server(['documents' => $loader], $transformer)->serve(new Request(), 'photo.jpg', 'documents');
            self::fail('A NotFoundHttpException was expected.');
        } catch (NotFoundHttpException $e) {
            self::assertSame($exception, $e->getPrevious());
        }
    }

    public function testAnUnavailableSourceIsAnUncacheable503(): void
    {
        $unavailable = new ImageSourceUnavailableException('Storage down.');
        $loader = self::createStub(ServableLoaderInterface::class);
        $loader->method('load')->willReturn(new Image(path: 'photo.jpg'));
        $transformer = self::createStub(LocalTransformerInterface::class);
        $transformer->method('serve')->willThrowException($unavailable);

        try {
            $this->server(['documents' => $loader], $transformer)->serve(new Request(), 'photo.jpg', 'documents');
            self::fail('A ServiceUnavailableHttpException was expected.');
        } catch (ServiceUnavailableHttpException $e) {
            self::assertSame($unavailable, $e->getPrevious());
            self::assertSame('no-store', $e->getHeaders()['Cache-Control'] ?? null);
        }
    }

    public function testTheTransformerMustServe(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Transformer "glide" cannot serve images');

        $this->server(['documents' => self::createStub(ServableLoaderInterface::class)], self::createStub(ImageTransformerInterface::class))
            ->serve(new Request(), 'photo.jpg', 'documents');
    }

    public function testTheLoaderMustBeServable(): void
    {
        $loader = self::createStub(ImageLoaderInterface::class);
        $loader->method('load')->willReturn(new Image(path: 'photo.jpg'));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "remote" cannot be served');

        $this->server(['remote' => $loader], self::createStub(LocalTransformerInterface::class))->serve(new Request(), 'photo.jpg', 'remote');
    }

    /**
     * @param array<string, ImageLoaderInterface> $loaders
     * @param array<string, string>               $defaultTransformers
     */
    private function server(array $loaders, ImageTransformerInterface $transformer, array $defaultTransformers = []): ImageServer
    {
        $loaderContainer = self::createStub(ContainerInterface::class);
        $loaderContainer->method('has')->willReturnCallback(static fn (string $name): bool => isset($loaders[$name]));
        $loaderContainer->method('get')->willReturnCallback(static fn (string $name): ImageLoaderInterface => $loaders[$name]);
        $loaderRegistry = new LoaderRegistry($loaderContainer, defaultTransformers: $defaultTransformers);

        $transformerContainer = self::createStub(ContainerInterface::class);
        $transformerContainer->method('has')->willReturn(true);
        $transformerContainer->method('get')->willReturn($transformer);
        $transformerRegistry = new TransformerRegistry($transformerContainer);

        return new ImageServer(new ImagePipeline($loaderRegistry, $transformerRegistry, null, 'glide'), $loaderRegistry, $transformerRegistry);
    }
}
