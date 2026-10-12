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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Transformer\UrlTemplates;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class UrlTemplatesTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, array<string, int|string>}>
     */
    public static function provideUrls(): iterable
    {
        $parameters = ['transformer' => 'glide', 'loader' => 'filesystem', 'path' => 'photos/cat.jpg'];

        yield 'width and format' => [$parameters, ['w' => 640, 'fm' => 'webp', 's' => 'a1b2c3']];
        yield 'every parameter' => [$parameters, ['w' => 640, 'h' => 480, 'fm' => 'avif', 'q' => 75, 'fit' => 'crop-center', 'blur' => 5, 'dpr' => 2, 's' => 'a1b2c3']];
        yield 'path to encode' => [['path' => 'dossier/été 2024/photo (1).jpg'] + $parameters, ['w' => 320, 's' => 'ff00']];
        yield 'value to encode' => [$parameters, ['w' => 320, 'fit' => 'crop 50,50', 's' => 'ff00']];
        yield 'path holding a placeholder' => [['path' => 'PicassoPh0Z.jpg'] + $parameters, ['w' => 320, 's' => 'ff00']];
    }

    /**
     * @param array<string, string>     $parameters
     * @param array<string, int|string> $query
     */
    #[DataProvider('provideUrls')]
    public function testGeneratesWhatTheRouterGenerates(array $parameters, array $query): void
    {
        $router = $this->createRouter();
        $templates = new UrlTemplates($router);

        // Twice: the second URL comes from the template when there is one
        foreach ([$query, ['w' => 1920, 's' => 'ffff'] + $query] as $values) {
            self::assertSame(
                $router->generate('picasso_image', [...$parameters, ...$values], UrlGeneratorInterface::ABSOLUTE_PATH),
                $templates->generate('picasso_image', $parameters, $values),
            );
        }
    }

    public function testAsksTheRouterOncePerImage(): void
    {
        $router = new CountingUrlGenerator($this->createRouter());
        $templates = new UrlTemplates($router);
        $parameters = ['transformer' => 'glide', 'loader' => 'filesystem', 'path' => 'cat.jpg'];

        foreach ([320, 640, 1280, 1920] as $width) {
            foreach (['avif', 'webp', 'jpg'] as $format) {
                $templates->generate('picasso_image', $parameters, ['w' => $width, 'fm' => $format, 's' => md5($width . $format)]);
            }
        }
        self::assertSame(1, $router->calls);

        $templates->generate('picasso_image', ['path' => 'dog.jpg'] + $parameters, ['w' => 320, 'fm' => 'avif', 's' => 'ff']);
        $templates->generate('picasso_image', $parameters, ['w' => 320, 's' => 'ff']);
        self::assertSame(3, $router->calls, 'Another image or other query keys need their own template');
    }

    public function testFollowsTheRequestContext(): void
    {
        $router = $this->createRouter();
        $templates = new UrlTemplates($router);
        $parameters = ['transformer' => 'glide', 'loader' => 'filesystem', 'path' => 'cat.jpg'];

        self::assertSame('/image/glide/filesystem/cat.jpg?w=320&s=ff', $templates->generate('picasso_image', $parameters, ['w' => 320, 's' => 'ff']));

        // e.g. the next request of a worker, served under another base URL
        $router->getContext()->setBaseUrl('/app.php');
        self::assertSame('/app.php/image/glide/filesystem/cat.jpg?w=640&s=ee', $templates->generate('picasso_image', $parameters, ['w' => 640, 's' => 'ee']));
    }

    public function testValuesTheRouterWouldEncodeOrPrintDifferentlyGoThroughTheRouter(): void
    {
        $router = new CountingUrlGenerator($this->createRouter());
        $templates = new UrlTemplates($router);
        $parameters = ['transformer' => 'glide', 'loader' => 'filesystem', 'path' => 'cat.jpg'];

        self::assertSame('/image/glide/filesystem/cat.jpg?fit=a%20b&s=ff', $templates->generate('picasso_image', $parameters, ['fit' => 'a b', 's' => 'ff']));
        self::assertSame('/image/glide/filesystem/cat.jpg?fit=0&s=ff', $templates->generate('picasso_image', $parameters, ['fit' => false, 's' => 'ff']));
        self::assertSame(2, $router->calls);
    }

    public function testKeepsABoundedNumberOfTemplates(): void
    {
        $router = new CountingUrlGenerator($this->createRouter());
        $templates = new UrlTemplates($router);

        for ($i = 0; $i < 65; ++$i) {
            $templates->generate('picasso_image', ['transformer' => 'glide', 'loader' => 'filesystem', 'path' => $i . '.jpg'], ['w' => 320, 's' => 'ff']);
        }
        self::assertSame(65, $router->calls);

        // The 65th template replaced the 64 previous ones
        $templates->generate('picasso_image', ['transformer' => 'glide', 'loader' => 'filesystem', 'path' => '0.jpg'], ['w' => 640, 's' => 'ee']);
        self::assertSame(66, $router->calls);
    }

    private function createRouter(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('picasso_image', new Route('/image/{transformer}/{loader}/{path}', requirements: ['path' => '.+']));

        return new UrlGenerator($routes, new RequestContext());
    }
}

/**
 * @internal
 */
final class CountingUrlGenerator implements UrlGeneratorInterface
{
    public int $calls = 0;

    public function __construct(private readonly UrlGeneratorInterface $router)
    {
    }

    /**
     * @param mixed[] $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        ++$this->calls;

        return $this->router->generate($name, $parameters, $referenceType);
    }

    public function setContext(RequestContext $context): void
    {
        $this->router->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->router->getContext();
    }
}
