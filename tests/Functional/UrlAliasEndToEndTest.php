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

use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Service\ImagePipeline;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Image URLs name the transformer and loader by their URL alias, the controller
 * serves them, and public-cache keys keep mirroring the URL path.
 */
class UrlAliasEndToEndTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return UrlAliasKernel::class;
    }

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        (new Filesystem())->remove($kernel->getCacheDir() . '/bucket');
    }

    public function testAnAliasedUrlIsServedAndCachedUnderItsPath(): void
    {
        $url = $this->pipeline()->url(new ImageReference('photo.jpg'), new ImageTransformation(width: 10, format: 'webp'));

        self::assertStringStartsWith('/image/g/pi/photo.jpg/', $url);

        $response = $this->kernel()->handle(Request::create($url));
        $key = rawurldecode(ltrim((string) parse_url($url, \PHP_URL_PATH), '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertFileExists($this->kernel()->getCacheDir() . '/bucket/' . $key);
    }

    public function testPurgeRemovesTheVariantsStoredUnderTheAliases(): void
    {
        $url = $this->pipeline()->url(new ImageReference('photo.jpg'), new ImageTransformation(width: 10, format: 'webp'));
        $this->kernel()->handle(Request::create($url));

        $this->pipeline()->purge('photo.jpg');

        self::assertDirectoryDoesNotExist($this->kernel()->getCacheDir() . '/bucket/image/g/pi/photo.jpg');
    }

    private function pipeline(): ImagePipeline
    {
        $pipeline = self::getContainer()->get('picasso.pipeline');
        assert($pipeline instanceof ImagePipeline);

        return $pipeline;
    }

    private function kernel(): Kernel
    {
        $kernel = self::$kernel;
        assert($kernel instanceof Kernel);

        return $kernel;
    }
}
