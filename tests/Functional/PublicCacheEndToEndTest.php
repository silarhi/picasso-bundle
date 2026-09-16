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

use League\Glide\Signatures\SignatureFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * End-to-end tests that exercise GlideTransformer's public cache mode.
 * Uses PublicCacheKernel which has public_cache.enabled=true on the Glide transformer.
 *
 * Covers GlideTransformer::serve() lines 122-140 (public cache path parsing),
 * lines 168-170 (custom cache path callable), and lines 179-180 (FileNotFoundException catch).
 */
class PublicCacheEndToEndTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    protected static function getKernelClass(): string
    {
        return PublicCacheKernel::class;
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

    public function testPublicCacheUrlContainsTransformationParamsInPath(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $srcUrl = $this->parseSrcFromImg($html);

        // In public cache mode, transformation params are in the path segment, with
        // commas percent-encoded (e.g. fit_contain%2Cfm_webp%2Cq_75%2Cw_300.webp)
        self::assertMatchesRegularExpression('/\/photo\.jpg\/[a-z0-9_]+(?:%2C[a-z0-9_]+)*\.\w+/', $srcUrl);
        self::assertStringNotContainsString(',', $srcUrl);
    }

    public function testPublicCacheServeFallbackSrc(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $srcUrl = $this->parseSrcFromImg($html);
        $response = $this->handleRequest($srcUrl);

        self::assertSame(200, $response->getStatusCode(), 'Public cache fallback src serving failed: ' . $response->getContent());
        self::assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
    }

    public function testPublicCacheServeImgSrcsetEntry(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        // Use <img> srcset instead of <source> — source entries may use formats
        // not supported by the GD driver (avif), causing legitimate 404s.
        $url = $this->parseFirstSrcsetUrl('/<img[^>]+srcset="([^"]+)"/', $html);
        $response = $this->handleRequest($url);

        self::assertSame(200, $response->getStatusCode(), 'Public cache img srcset entry serving failed: ' . $response->getContent());
        self::assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
    }

    public function testLegacyQueryStringUrlRedirectsToCanonicalUrl(): void
    {
        self::bootKernel();

        $legacyUrl = $this->buildLegacyUrl('photo.jpg', ['fit' => 'contain', 'fm' => 'jpg', 'h' => '50', 'q' => '75', 'w' => '100']);

        $response = $this->handleRequest($legacyUrl);

        self::assertSame(301, $response->getStatusCode(), 'Legacy URL should redirect: ' . $response->getContent());

        $target = (string) $response->headers->get('Location');
        self::assertStringContainsString('/photo.jpg/fit_contain%2Cfm_jpg%2Ch_50%2Cq_75%2Cw_100.jpg', $target);
        self::assertStringNotContainsString('w=100', $target, 'Transformation params should move into the path');

        // The target must itself be servable, or we would redirect into a 404.
        self::assertSame(200, $this->handleRequest($target)->getStatusCode());
    }

    public function testLegacyUrlWithoutTransformationParamsRedirects(): void
    {
        self::bootKernel();

        $legacyUrl = $this->buildLegacyUrl('photo.jpg', []);

        $response = $this->handleRequest($legacyUrl);

        self::assertSame(301, $response->getStatusCode(), 'Untransformed legacy URL should redirect: ' . $response->getContent());
    }

    public function testLegacyUrlWithTamperedSignatureReturns404(): void
    {
        self::bootKernel();

        $legacyUrl = $this->buildLegacyUrl('photo.jpg', ['fm' => 'jpg', 'w' => '100']);
        $tamperedUrl = preg_replace('/s=[a-f0-9]+/', 's=tampered', $legacyUrl);
        self::assertNotNull($tamperedUrl);

        $response = $this->handleRequest($tamperedUrl);

        self::assertSame(404, $response->getStatusCode(), 'A forged legacy URL must not redirect: ' . $response->getContent());
    }

    public function testPublicCacheTamperedSignatureReturns404(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'photo.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $srcUrl = $this->parseSrcFromImg($html);
        $tamperedUrl = preg_replace('/s=[a-f0-9]+/', 's=tampered', $srcUrl);
        self::assertNotNull($tamperedUrl);

        $response = $this->handleRequest($tamperedUrl);
        self::assertSame(404, $response->getStatusCode(), 'Tampered signature should return 404: ' . $response->getContent());
    }

    public function testPublicCacheNonExistentImageReturns404(): void
    {
        $rendered = $this->renderTwigComponent('Picasso:Image', [
            'src' => 'does_not_exist.jpg',
            'sizes' => '100vw',
        ]);
        $html = $rendered->toString();

        $srcUrl = $this->parseSrcFromImg($html);
        $response = $this->handleRequest($srcUrl);
        self::assertSame(404, $response->getStatusCode(), 'Non-existent image should return 404: ' . $response->getContent());
    }

    /**
     * Build a URL in the pre-public-cache shape: transformation params in the
     * query string, signed over that path and those params.
     *
     * @param array<string, string> $params
     */
    private function buildLegacyUrl(string $path, array $params): string
    {
        $signature = SignatureFactory::create('public-cache-key')->generateSignature($path, $params);

        return '/image/glide/filesystem/' . $path . '?' . http_build_query([...$params, 's' => $signature]);
    }

    private function handleRequest(string $url): Response
    {
        $kernel = self::$kernel;
        assert($kernel instanceof KernelInterface);

        return $kernel->handle(Request::create($url));
    }

    private function parseSrcFromImg(string $html): string
    {
        $found = preg_match('/<img[^>]+src="([^"]+)"/', $html, $matches);
        self::assertSame(1, $found, 'Could not find src attribute in rendered HTML');

        return html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5);
    }

    private function parseFirstSrcsetUrl(string $pattern, string $html): string
    {
        $found = preg_match($pattern, $html, $matches);
        self::assertSame(1, $found, 'Could not find srcset in rendered HTML');

        $srcsetValue = html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5);

        // In public cache mode, URLs contain commas (e.g., fit_contain,fm_jpg,q_75,w_16.jpg).
        // Use regex to extract the first URL before a width descriptor.
        $urlFound = preg_match('#(\S+)\s+\d+w#', $srcsetValue, $urlMatch);
        self::assertSame(1, $urlFound, 'Could not parse srcset URL');

        return $urlMatch[1];
    }
}
