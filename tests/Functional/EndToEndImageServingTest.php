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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * End-to-end tests that render the Picasso:Image Twig component,
 * parse the generated image URLs from the HTML, and make actual
 * HTTP GET requests to the controller to verify image serving.
 */
class EndToEndImageServingTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    protected static function getKernelClass(): string
    {
        return FullConfigKernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $kernel = self::bootKernel();
        $glideCache = $kernel->getCacheDir() . '/glide';
        if (!is_dir($glideCache)) {
            mkdir($glideCache, 0777, true);
        }
    }

    public function testRenderAndServeFallbackSrc(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $srcUrl = $this->parseSrcFromImg($html);
        self::assertStringContainsString('/image/', $srcUrl);

        $response = self::handleRequest($srcUrl);

        self::assertSame(200, $response->getStatusCode(), 'Fallback src serving failed');
        self::assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
    }

    public function testRenderAndServeSrcsetEntries(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $url = $this->parseFirstSrcsetUrl('/<img[^>]+srcset="([^"]+)"/', $html);
        self::assertStringContainsString('/image/', $url);

        $response = self::handleRequest($url);

        self::assertSame(200, $response->getStatusCode(), 'Srcset entry serving failed');
        self::assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
    }

    public function testRenderAndServeSourceSrcset(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $url = $this->parseFirstSrcsetUrl('/<source[^>]+srcset="([^"]+)"/', $html);
        self::assertStringContainsString('/image/', $url);

        $response = self::handleRequest($url);

        self::assertSame(200, $response->getStatusCode(), 'Source srcset entry serving failed');
        self::assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
    }

    public function testRenderWithBlurPlaceholderAndServe(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
            'placeholder' => true,
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('background-image:url(', $html);

        $srcUrl = $this->parseSrcFromImg($html);
        $response = self::handleRequest($srcUrl);
        self::assertSame(200, $response->getStatusCode(), 'Blur variant fallback src failed');
    }

    public function testRenderWithPriorityAndServe(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
            'priority' => true,
        ]);
        $html = $rendered->toString();

        self::assertStringContainsString('loading="eager"', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);

        $srcUrl = $this->parseSrcFromImg($html);
        $response = self::handleRequest($srcUrl);
        self::assertSame(200, $response->getStatusCode(), 'Priority image serving failed');
        self::assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
    }

    public function testRenderWithDifferentLoaderAndServe(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'pixel.gif',
            'loader' => 'secondary_fs',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $srcUrl = $this->parseSrcFromImg($html);
        self::assertStringContainsString('secondary_fs', $srcUrl);

        $response = self::handleRequest($srcUrl);
        self::assertSame(200, $response->getStatusCode(), 'Secondary loader image serving failed');
        self::assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
    }

    public function testGeneratedUrlUsesRegisteredTransformerName(): void
    {
        // FullConfigKernel registers the Glide transformer as 'local_glide', not 'glide'
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $srcUrl = $this->parseSrcFromImg($html);
        self::assertStringContainsString('/image/local_glide/', $srcUrl);

        $response = self::handleRequest($srcUrl);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testTamperedSignatureReturns404(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $srcUrl = $this->parseSrcFromImg($html);
        $tamperedUrl = preg_replace('/s=[a-f0-9]+/', 's=tampered', $srcUrl);
        self::assertNotNull($tamperedUrl);

        $response = self::handleRequest($tamperedUrl);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testMultipleSrcsetEntriesAllServable(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        // Parse all srcset entries from <img> tag
        preg_match('/<img[^>]+srcset="([^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1], 'Could not find img srcset');

        $srcsetValue = html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5);
        $entries = explode(',', $srcsetValue);

        // Serve up to 3 entries to keep test fast
        $tested = 0;
        foreach ($entries as $entry) {
            $url = explode(' ', trim($entry))[0];
            if ('' === $url) {
                continue;
            }

            $response = self::handleRequest($url);
            self::assertSame(200, $response->getStatusCode(), 'Srcset entry failed: ' . $url);
            ++$tested;
            if ($tested >= 3) {
                break;
            }
        }

        self::assertGreaterThan(0, $tested, 'No srcset entries were tested');
    }

    private static function handleRequest(string $url): Response
    {
        $kernel = self::$kernel;
        assert($kernel instanceof KernelInterface);

        return $kernel->handle(Request::create($url));
    }

    private function parseSrcFromImg(string $html): string
    {
        preg_match('/<img[^>]+src="([^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1], 'Could not find src attribute in rendered HTML');

        return html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5);
    }

    private function parseFirstSrcsetUrl(string $pattern, string $html): string
    {
        preg_match($pattern, $html, $matches);
        self::assertNotEmpty($matches[1], 'Could not find srcset in rendered HTML');

        $srcsetValue = html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5);
        $entries = explode(',', $srcsetValue);
        self::assertNotEmpty($entries);

        return explode(' ', trim($entries[0]))[0];
    }
}
