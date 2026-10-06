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

namespace Silarhi\PicassoBundle\Tests\Functional;

use function assert;
use function count;

use League\Glide\Signatures\SignatureFactory;
use Silarhi\PicassoBundle\Exception\InvalidRouteException;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * Images of a private loader are rendered with every URL on an application
 * route, which decides access before ImageServer serves them; the bundle route
 * refuses them.
 */
class PrivateRouteEndToEndTest extends KernelTestCase
{
    private const COMPONENT = <<<'TWIG'
        <twig:Picasso:Image src="photo.jpg" sizes="100vw" placeholder="blur"
            route="portal_document_image" routeParameters="{{ {tenant: 'acme', id: 42} }}" />
        TWIG;

    protected static function getKernelClass(): string
    {
        return PrivateRouteKernel::class;
    }

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        (new Filesystem())->remove($kernel->getCacheDir() . '/glide');
    }

    public function testEveryUrlOfTheComponentPointsAtTheRoute(): void
    {
        $urls = $this->urls($this->render(self::COMPONENT));

        // srcset candidates of each format, the fallback src and the blur placeholder
        self::assertGreaterThan(10, count($urls));
        foreach ($urls as $url) {
            self::assertStringStartsWith('/portal/acme/documents/42/image?', $url);
            self::assertStringNotContainsString('photo.jpg', $url);
        }
    }

    public function testTheRouteServesEachUrlItAuthorizes(): void
    {
        foreach ($this->urls($this->render(self::COMPONENT)) as $url) {
            $response = $this->get($url);

            self::assertSame(200, $response->getStatusCode(), $url);
            self::assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
            self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
            self::assertFalse($response->headers->has('Expires'));
        }
    }

    public function testTheRouteDecidesAccessBeforeServing(): void
    {
        $url = $this->urls($this->render(self::COMPONENT))[0];

        self::assertSame(403, $this->get($url, granted: false)->getStatusCode());
    }

    public function testConditionalRequestsStayPrivate(): void
    {
        $url = $this->urls($this->render(self::COMPONENT))[0];
        $lastModified = $this->get($url)->headers->get('Last-Modified');
        self::assertNotNull($lastModified);

        $response = $this->get($url, headers: ['HTTP_IF_MODIFIED_SINCE' => $lastModified]);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
    }

    public function testATamperedTransformationIsNotServed(): void
    {
        $url = $this->urls($this->render(self::COMPONENT))[0];

        self::assertSame(404, $this->get(preg_replace('/\bw=\d+/', 'w=4000', $url) ?? $url)->getStatusCode());
    }

    public function testAUrlSignedForAnotherImageIsNotServed(): void
    {
        // Document 7 is another image: the signature of photo.jpg does not match it
        $url = str_replace('/documents/42/', '/documents/7/', $this->urls($this->render(self::COMPONENT))[0]);

        self::assertSame(404, $this->get($url)->getStatusCode());
    }

    public function testRouteParametersOutsideThePathAreSigned(): void
    {
        $url = $this->imageHelper()->imageUrl('photo.jpg', width: 32, route: 'portal_document_image', routeParameters: ['tenant' => 'acme', 'id' => 42, 'download' => 1]);

        self::assertStringContainsString('download=1', $url);
        self::assertSame(200, $this->get($url)->getStatusCode());
    }

    public function testTheBundleRouteRefusesThePrivateLoader(): void
    {
        $params = ['w' => '32'];
        $params['s'] = SignatureFactory::create(PrivateRouteKernel::SIGN_KEY)->generateSignature('photo.jpg', $params);

        self::assertSame(404, $this->get('/image/glide/documents/photo.jpg?' . http_build_query($params))->getStatusCode());
        // The same signed URL is served for a loader that is not private
        self::assertSame(200, $this->get('/image/glide/public/photo.jpg?' . http_build_query($params))->getStatusCode());
    }

    public function testAPrivateLoaderCannotBeRenderedWithoutARoute(): void
    {
        try {
            $this->render('<twig:Picasso:Image src="photo.jpg" sizes="100vw" />');
            self::fail('Rendering a private loader without a route must fail.');
        } catch (RuntimeError $e) {
            self::assertInstanceOf(InvalidRouteException::class, $e->getPrevious());
            self::assertStringContainsString('Loader "documents" is private', $e->getPrevious()->getMessage());
        }
    }

    public function testTheTwigFunctionTakesTheRouteToo(): void
    {
        $html = $this->render("{{ picasso_image(src='photo.jpg', sizes='100vw', route='portal_document_image', routeParameters={tenant: 'acme', id: 42}) }}");

        foreach ($this->urls($html) as $url) {
            self::assertStringStartsWith('/portal/acme/documents/42/image?', $url);
        }
    }

    private function render(string $template): string
    {
        $twig = self::getContainer()->get('twig');
        assert($twig instanceof Environment);

        return $twig->createTemplate($template)->render();
    }

    /**
     * @return list<string>
     */
    private function urls(string $html): array
    {
        preg_match_all('#/(?:portal|image)/[^"\s\')]+#', html_entity_decode($html), $matches);

        return $matches[0];
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $url, bool $granted = true, array $headers = []): Response
    {
        $kernel = self::$kernel;
        assert($kernel instanceof Kernel);

        $server = $granted ? ['HTTP_X_ACCESS' => 'granted', ...$headers] : $headers;

        return $kernel->handle(Request::create($url, server: $server));
    }

    private function imageHelper(): ImageHelperInterface
    {
        $helper = self::getContainer()->get(ImageHelperInterface::class);
        assert($helper instanceof ImageHelperInterface);

        return $helper;
    }
}
