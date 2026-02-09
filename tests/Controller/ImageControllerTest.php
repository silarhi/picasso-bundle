<?php

namespace Silarhi\PicassoBundle\Tests\Controller;

use League\Glide\Server;
use League\Glide\Signatures\SignatureFactory;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Controller\ImageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageControllerTest extends TestCase
{
    private const SIGN_KEY = 'test-secret-key';

    public function testServeReturnsImageResponse(): void
    {
        $path = 'photo.jpg';
        $params = ['w' => '300', 'fm' => 'webp'];
        $signature = SignatureFactory::create(self::SIGN_KEY)->generateSignature($path, $params);
        $params['s'] = $signature;

        $request = new Request($params);
        $expectedResponse = new Response('image-data', 200, ['Content-Type' => 'image/webp']);

        $server = $this->createMock(Server::class);
        $server->expects(self::once())
            ->method('setResponseFactory');
        $server->expects(self::once())
            ->method('getImageResponse')
            ->with($path, $params)
            ->willReturn($expectedResponse);

        $controller = new ImageController($server, self::SIGN_KEY);
        $response = $controller->serve($path, $request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testServeThrowsNotFoundOnInvalidSignature(): void
    {
        $request = new Request(['w' => '300', 's' => 'invalid-signature']);

        $server = $this->createMock(Server::class);
        $server->expects(self::never())->method('getImageResponse');

        $controller = new ImageController($server, self::SIGN_KEY);

        $this->expectException(NotFoundHttpException::class);
        $controller->serve('photo.jpg', $request);
    }

    public function testServeThrowsNotFoundOnMissingImage(): void
    {
        $path = 'missing.jpg';
        $params = ['w' => '300'];
        $signature = SignatureFactory::create(self::SIGN_KEY)->generateSignature($path, $params);
        $params['s'] = $signature;

        $request = new Request($params);

        $server = $this->createMock(Server::class);
        $server->method('setResponseFactory');
        $server->method('getImageResponse')
            ->willThrowException(new \InvalidArgumentException('File not found'));

        $controller = new ImageController($server, self::SIGN_KEY);

        $this->expectException(NotFoundHttpException::class);
        $controller->serve($path, $request);
    }
}
