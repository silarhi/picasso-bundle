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

namespace Silarhi\PicassoBundle\Tests\Transformer;

use function is_string;

use League\Glide\Signatures\SignatureFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\InvalidRouteException;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;

use function sprintf;

use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Glide URLs on an application route, served through ImageServer.
 */
class GlideTransformerRouteTest extends TestCase
{
    private const SIGN_KEY = 'route-secret-key';

    public function testUrlPointsAtTheRouteWithTheTransformationInTheQuery(): void
    {
        $url = $this->transformer()->url(
            new Image(path: 'contracts/42.jpg'),
            new ImageTransformation(width: 640, format: 'webp'),
            $this->context(['tenant' => 'acme', 'id' => 42]),
        );

        self::assertStringStartsWith('/portal/acme/documents/42/image?', $url);
        self::assertStringNotContainsString('contracts', $url, 'The image path stays out of routed URLs');
        $query = $this->query($url);
        self::assertSame('640', $query['w']);
        self::assertSame('webp', $query['fm']);
    }

    public function testSignatureCoversTheImagePathAndTheWholeQuery(): void
    {
        $url = $this->transformer()->url(
            new Image(path: 'contracts/42.jpg'),
            new ImageTransformation(width: 640),
            // "download" is no placeholder of the route: the router puts it in the query
            $this->context(['tenant' => 'acme', 'id' => 42, 'download' => 1]),
        );

        $query = $this->query($url);
        self::assertSame('1', $query['download']);

        SignatureFactory::create(self::SIGN_KEY)->validateRequest('contracts/42.jpg', $query);
        $this->expectExceptionMessage('Signature is not valid.');
        SignatureFactory::create(self::SIGN_KEY)->validateRequest('contracts/43.jpg', $query);
    }

    public function testBaseUrlIsNotPrependedToRoutedUrls(): void
    {
        $url = $this->transformer(baseUrl: 'https://cdn.example.com')->url(
            new Image(path: 'photo.jpg'),
            new ImageTransformation(width: 100),
            $this->context(['tenant' => 'acme', 'id' => 1]),
        );

        self::assertStringStartsWith('/portal/acme/documents/1/image?', $url);
    }

    public function testUrlWithoutQueryParamsStillCarriesTheSignature(): void
    {
        $url = $this->transformer()->url(
            new Image(path: 'photo.jpg'),
            new ImageTransformation(),
            $this->context(['tenant' => 'acme', 'id' => 1]),
        );

        self::assertMatchesRegularExpression('#^/portal/acme/documents/1/image\?s=[0-9a-f]{32}$#', $url);
    }

    public function testPublicCacheCannotServeRoutes(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('public_cache');

        $this->transformer(publicCache: true)->url(
            new Image(path: 'photo.jpg'),
            new ImageTransformation(width: 100),
            $this->context(['tenant' => 'acme', 'id' => 1]),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedParameters(): iterable
    {
        yield 'transformation param' => ['w'];
        yield 'signature' => ['s'];
        yield 'fragment' => ['_fragment'];
    }

    #[DataProvider('reservedParameters')]
    public function testRouteParametersCannotClashWithTheQueryOfTheImage(string $parameter): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage(sprintf('"%s"', $parameter));

        $this->transformer()->url(
            new Image(path: 'photo.jpg'),
            new ImageTransformation(width: 100),
            $this->context(['tenant' => 'acme', 'id' => 1, $parameter => 'x']),
        );
    }

    public function testRouteMustBeAName(): void
    {
        $this->expectException(InvalidRouteException::class);

        $this->transformer()->url(
            new Image(path: 'photo.jpg'),
            new ImageTransformation(width: 100),
            ['loader' => 'documents', 'transformer' => 'glide', 'route' => 42],
        );
    }

    private function transformer(bool $publicCache = false, ?string $baseUrl = null): GlideTransformer
    {
        $routes = new RouteCollection();
        $routes->add('portal_document_image', new Route('/portal/{tenant}/documents/{id}/image'));

        return new GlideTransformer(
            new UrlGenerator($routes, new RequestContext()),
            new UrlAliases([], []),
            self::SIGN_KEY,
            sys_get_temp_dir() . '/picasso_test/glide_route',
            'gd',
            null,
            $publicCache,
            baseUrl: $baseUrl,
        );
    }

    /**
     * @param array<string, mixed> $routeParameters
     *
     * @return array<string, mixed>
     */
    private function context(array $routeParameters): array
    {
        return [
            'loader' => 'documents',
            'transformer' => 'glide',
            'route' => 'portal_document_image',
            'route_parameters' => $routeParameters,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function query(string $url): array
    {
        $query = parse_url($url, \PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $params);

        return $params;
    }
}
