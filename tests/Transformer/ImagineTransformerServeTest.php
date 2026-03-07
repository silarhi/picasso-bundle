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

use function assert;
use function in_array;
use function is_string;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\UrlEncryption;
use Silarhi\PicassoBundle\Transformer\ImagineTransformer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ImagineTransformerServeTest extends TestCase
{
    private const SIGN_KEY = 'test-secret-key';

    private string $sourceDir;
    private string $cacheDir;
    private ImagineTransformer $transformer;

    protected function setUp(): void
    {
        $this->sourceDir = sys_get_temp_dir() . '/picasso_imagine_test_' . bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir() . '/picasso_imagine_cache_' . bin2hex(random_bytes(4));

        (new Filesystem())->mkdir($this->sourceDir);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')
            ->willReturnCallback(static function (string $name, array $params): string {
                assert(is_string($params['transformer']));
                assert(is_string($params['loader']));
                assert(is_string($params['path']));

                return '/picasso/' . $params['transformer'] . '/' . $params['loader'] . '/' . $params['path'] . '?' . http_build_query(
                    array_filter($params, static fn ($k): bool => !in_array($k, ['transformer', 'loader', 'path'], true), \ARRAY_FILTER_USE_KEY),
                );
            });

        $this->transformer = new ImagineTransformer(
            $router,
            new UrlEncryption(self::SIGN_KEY),
            self::SIGN_KEY,
            $this->cacheDir,
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->sourceDir, $this->cacheDir]);
    }

    public function testServeResizesImage(): void
    {
        $this->createTestImage('photo.jpg', 200, 150);

        $url = $this->generateUrl('photo.jpg', new ImageTransformation(width: 100, height: 75));
        $request = Request::create($url);

        $response = $this->transformer->serve($this->createLoader(), 'photo.jpg', $request);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        $outputPath = $response->getFile()->getPathname();
        $size = getimagesize($outputPath);
        self::assertNotFalse($size);
        self::assertSame(100, $size[0]);
        self::assertSame(75, $size[1]);
    }

    public function testServeAppliesBlur(): void
    {
        $this->createTestImage('blurme.jpg', 100, 100);

        $url = $this->generateUrl('blurme.jpg', new ImageTransformation(width: 50, height: 50, blur: 5));
        $request = Request::create($url);

        $response = $this->transformer->serve($this->createLoader(), 'blurme.jpg', $request);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testServeConvertsFormat(): void
    {
        $this->createTestImage('convert.jpg', 100, 100);

        $url = $this->generateUrl('convert.jpg', new ImageTransformation(width: 50, format: 'png'));
        $request = Request::create($url);

        $response = $this->transformer->serve($this->createLoader(), 'convert.jpg', $request);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame('image/png', $response->headers->get('Content-Type'));
    }

    public function testServeRejectsInvalidSignature(): void
    {
        $this->createTestImage('photo.jpg', 100, 100);

        $request = Request::create('/picasso/imagine/filesystem/photo.jpg?w=100&s=invalidsig');

        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('Invalid image signature');
        $this->transformer->serve($this->createLoader(), 'photo.jpg', $request);
    }

    public function testServeRejectsMissingImage(): void
    {
        $url = $this->generateUrl('nonexistent.jpg', new ImageTransformation(width: 100));
        $request = Request::create($url);

        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessage('not found');
        $this->transformer->serve($this->createLoader(), 'nonexistent.jpg', $request);
    }

    public function testServeCachesResult(): void
    {
        $this->createTestImage('cached.jpg', 200, 150);

        $url = $this->generateUrl('cached.jpg', new ImageTransformation(width: 80));
        $request = Request::create($url);

        // First request creates cache
        $response1 = $this->transformer->serve($this->createLoader(), 'cached.jpg', $request);
        self::assertInstanceOf(BinaryFileResponse::class, $response1);
        $cachePath = $response1->getFile()->getPathname();
        self::assertFileExists($cachePath);

        // Second request uses cache
        $response2 = $this->transformer->serve($this->createLoader(), 'cached.jpg', $request);
        self::assertInstanceOf(BinaryFileResponse::class, $response2);
        self::assertSame($cachePath, $response2->getFile()->getPathname());
    }

    private function generateUrl(string $path, ImageTransformation $transformation): string
    {
        return $this->transformer->url(
            new Image(path: $path),
            $transformation,
            ['loader' => 'filesystem', 'transformer' => 'imagine'],
        );
    }

    private function createLoader(): ServableLoaderInterface
    {
        $loader = $this->createMock(ServableLoaderInterface::class);
        $loader->method('getSource')->willReturn($this->sourceDir);

        return $loader;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function createTestImage(string $filename, int $width, int $height): void
    {
        $gd = imagecreatetruecolor($width, $height);
        self::assertNotFalse($gd);

        $color = imagecolorallocate($gd, 100, 150, 200);
        self::assertNotFalse($color);
        imagefill($gd, 0, 0, $color);

        imagejpeg($gd, $this->sourceDir . '/' . $filename, 90);
        imagedestroy($gd);
    }
}
