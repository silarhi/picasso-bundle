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

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The CDN setup end to end: a URL minted for the CDN host misses, falls back to
 * the application, and the variant lands in the cache storage under the exact
 * key the CDN looks up (the URL path).
 */
class CdnEndToEndTest extends KernelTestCase
{
    private const CONTEXT = ['transformer' => 'glide', 'loader' => 'filesystem'];

    protected static function getKernelClass(): string
    {
        return CdnKernel::class;
    }

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        (new Filesystem())->remove($kernel->getCacheDir() . '/bucket');
    }

    public function testAMissIsRenderedAndStoredUnderTheUrlPath(): void
    {
        $url = $this->transformer()->url(new Image(path: 'photo.jpg'), new ImageTransformation(width: 10, format: 'webp'), self::CONTEXT);

        self::assertStringStartsWith('https://cdn.example.com/image/glide/filesystem/photo.jpg/', $url);

        $kernel = $this->kernel();
        $response = $kernel->handle(Request::create($url));
        $key = rawurldecode(ltrim((string) parse_url($url, \PHP_URL_PATH), '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
        self::assertFileExists($kernel->getCacheDir() . '/bucket/' . $key, 'The cache key must be the URL path, so the CDN finds the variant in the bucket.');
    }

    public function testAMissingImageIsACacheable404(): void
    {
        $url = $this->transformer()->url(new Image(path: 'missing.jpg'), new ImageTransformation(width: 10, format: 'webp'), self::CONTEXT);

        $response = $this->kernel()->handle(Request::create($url));

        self::assertSame(404, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('60', $response->headers->getCacheControlDirective('max-age'));
    }

    private function transformer(): GlideTransformer
    {
        $transformer = self::getContainer()->get('picasso.transformer.glide');
        assert($transformer instanceof GlideTransformer);

        return $transformer;
    }

    private function kernel(): Kernel
    {
        $kernel = self::$kernel;
        assert($kernel instanceof Kernel);

        return $kernel;
    }
}
