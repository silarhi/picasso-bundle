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

use function assert;
use function is_string;

use League\Glide\Signatures\SignatureFactory;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Controller\LegacyUrlController;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\LegacyMetadataResolver;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Silarhi\PicassoBundle\Tests\Fixtures\CollectsDeprecationsTrait;
use Silarhi\PicassoBundle\Tests\Fixtures\LegacyMetadataToken;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;

use function sprintf;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class LegacyUrlControllerTest extends TestCase
{
    use CollectsDeprecationsTrait;

    private const SIGN_KEY = 'test-sign-key';
    private const FIXTURES = __DIR__ . '/../Fixtures';
    private const CACHE_CONTROL = ['max_age' => null, 'immutable' => false, 'error_max_age' => null];

    public function testRequestsWithoutMetadataGoToTheImageController(): void
    {
        $request = new Request(['w' => '10', 's' => 'signature']);

        self::assertSame('served', $this->createController($this->createImageControllerServing('glide', $request))('glide', 'fixtures', 'photo.jpg', $request)->getContent());
    }

    public function testRequestsForAnotherTransformerGoToTheImageController(): void
    {
        // Only Glide minted "_metadata"
        $request = new Request(['_metadata' => 'token']);

        self::assertSame('served', $this->createController($this->createImageControllerServing('imgix', $request))('imgix', 'fixtures', 'photo.jpg', $request)->getContent());
    }

    public function testRedirects1xUrlsToTheLoaderReadingTheirRoot(): void
    {
        $token = LegacyMetadataToken::mint(self::SIGN_KEY, ['path' => self::FIXTURES]);
        $request = new Request(SignatureFactory::create(self::SIGN_KEY)->addSignature('photo.jpg', ['w' => '10', '_metadata' => $token]));

        [$response, $deprecations] = self::collectDeprecations(fn (): Response => $this->createController()('glide', 'legacy', 'photo.jpg', $request));

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertSame('/image/glide/fixtures/photo.jpg', parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH));
        self::assertSame(['Since silarhi/picasso-bundle 2.0: A 1.x image URL of loader "legacy" was redirected to loader "fixtures". Support for 1.x URLs will be removed in 3.0.'], $deprecations);
    }

    public function testInvalid1xUrlsAreCacheable404s(): void
    {
        foreach ([
            'invalid token' => new Request(['w' => '10', '_metadata' => 'not-a-token', 's' => 'signature']),
            'forged signature' => new Request(['w' => '10', '_metadata' => LegacyMetadataToken::mint(self::SIGN_KEY, ['path' => self::FIXTURES]), 's' => 'forged']),
            'root no loader reads' => new Request(SignatureFactory::create(self::SIGN_KEY)->addSignature('photo.jpg', ['_metadata' => LegacyMetadataToken::mint(self::SIGN_KEY, ['path' => '/srv/old'])])),
        ] as $case => $request) {
            try {
                $this->createController(errorMaxAge: 60)('glide', 'legacy', 'photo.jpg', $request);
                self::fail(sprintf('Expected a NotFoundHttpException (%s).', $case));
            } catch (NotFoundHttpException $e) {
                self::assertSame(['Cache-Control' => 'public, max-age=60'], $e->getHeaders(), $case);
            }
        }
    }

    private function createController(?ImageController $imageController = null, ?int $errorMaxAge = null): LegacyUrlController
    {
        $empty = new ServiceLocator([]);

        return new LegacyUrlController(
            $imageController ?? new ImageController(new TransformerRegistry($empty), new LoaderRegistry($empty), self::CACHE_CONTROL, new UrlAliases([], [])),
            new ServiceLocator(['glide' => $this->createGlideTransformer(...)]),
            new LegacyMetadataResolver(self::SIGN_KEY, [self::FIXTURES => 'fixtures'], '/app'),
            new UrlAliases([], []),
            $errorMaxAge,
        );
    }

    private function createImageControllerServing(string $transformerName, Request $request): ImageController
    {
        $loader = self::createStub(ServableLoaderInterface::class);
        $transformer = $this->createMock(LocalTransformerInterface::class);
        $transformer->expects(self::once())->method('serve')->with($loader, 'photo.jpg', $request)->willReturn(new Response('served'));

        return new ImageController(
            new TransformerRegistry(new ServiceLocator([$transformerName => static fn (): LocalTransformerInterface => $transformer])),
            new LoaderRegistry(new ServiceLocator(['fixtures' => static fn (): ServableLoaderInterface => $loader])),
            self::CACHE_CONTROL,
            new UrlAliases([], []),
        );
    }

    private function createGlideTransformer(): GlideTransformer
    {
        $router = self::createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static function (string $name, array $params): string {
            assert(is_string($params['transformer']) && is_string($params['loader']) && is_string($params['path']));

            return '/image/' . $params['transformer'] . '/' . $params['loader'] . '/' . $params['path'];
        });

        return new GlideTransformer($router, new UrlAliases([], []), self::SIGN_KEY, sys_get_temp_dir() . '/picasso-legacy-url-test', 'gd', null, false);
    }
}
