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
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Exception\InvalidTransformationException;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Silarhi\PicassoBundle\Transformer\ImgixTransformer;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Modifiers are Glide's vocabulary: Glide reads them as they are, Imgix translates them.
 */
class ModifiersTest extends TestCase
{
    private const CONTEXT = ['loader' => 'fs', 'transformer' => 'glide'];

    /**
     * @return iterable<string, array{array<string, int|string>, string}>
     */
    public static function provideImgixModifiers(): iterable
    {
        yield 'auto orientation' => [['or' => 'auto'], ''];
        yield 'no rotation' => [['or' => 0], 'orient=1'];
        // Glide rotates counter-clockwise, Imgix's degree aliases clockwise
        yield 'rotation' => [['or' => 90], 'orient=270'];
        yield 'flip both' => [['flip' => 'both'], 'flip=hv'];
        yield 'flip' => [['flip' => 'h'], 'flip=h'];
        yield 'crop rectangle' => [['crop' => '100,50,10,20'], 'rect=10%2C20%2C100%2C50'];
        yield 'same name' => [['bri' => 10, 'con' => -5, 'sharp' => 20, 'bg' => 'ff0000'], 'bri=10&con=-5&sharp=20&bg=ff0000'];
        yield 'pixelate' => [['pixel' => 5], 'px=5'];
        yield 'greyscale' => [['filt' => 'greyscale'], 'sat=-100'];
        yield 'sepia' => [['filt' => 'sepia'], 'sepia=100'];
        yield 'border' => [['border' => '10,5000,overlay'], 'border=10%2C5000'];
        yield 'watermark' => [['mark' => 'https://example.com/logo.png', 'markw' => 50, 'markpos' => 'bottom-right'], 'mark=https%3A%2F%2Fexample.com%2Flogo.png&mark-w=50&mark-align=bottom%2Cright'];
        yield 'centered watermark' => [['markpos' => 'center'], 'mark-align=middle%2Ccenter'];
        yield 'imgix param' => [['auto' => 'compress'], 'auto=compress'];
    }

    /**
     * @param array<string, int|string> $modifiers
     */
    #[DataProvider('provideImgixModifiers')]
    public function testImgixTranslatesModifiers(array $modifiers, string $query): void
    {
        $url = (new ImgixTransformer('https://cdn.imgix.net'))->url(new Image(path: 'a.jpg'), new ImageTransformation(modifiers: $modifiers));

        self::assertSame('https://cdn.imgix.net/a.jpg' . ('' !== $query ? '?' . $query : ''), $url);
    }

    /**
     * @return iterable<string, array{array<string, int|string>, string}>
     */
    public static function provideInvalidImgixModifiers(): iterable
    {
        yield 'option param' => [['w' => 10], 'The "w" modifier is set by its own option'];
        yield 'imgix fit param' => [['crop' => 'faces'], 'Invalid "crop" modifier "faces": use "width,height,x,y".'];
        yield 'gamma' => [['gam' => '1.5'], 'The "gam" modifier has no Imgix equivalent'];
        yield 'filter' => [['filt' => 'negate'], 'The "filt" modifier "negate" has no Imgix equivalent'];
        yield 'orientation' => [['or' => 45], 'Invalid "or" modifier "45"'];
    }

    /**
     * @param array<string, int|string> $modifiers
     */
    #[DataProvider('provideInvalidImgixModifiers')]
    public function testImgixRejectsModifiersItCannotExpress(array $modifiers, string $message): void
    {
        $this->expectException(InvalidTransformationException::class);
        $this->expectExceptionMessage($message);

        (new ImgixTransformer('https://cdn.imgix.net'))->url(new Image(path: 'a.jpg'), new ImageTransformation(modifiers: $modifiers));
    }

    public function testGlideAddsModifiersAfterTheOptions(): void
    {
        $url = $this->glide()->url(new Image(path: 'a.jpg'), new ImageTransformation(width: 10, modifiers: ['flip' => 'h', 'crop' => '10,10,0,0']), self::CONTEXT);

        self::assertStringStartsWith('/image/glide/fs/a.jpg?w=10&flip=h&crop=10%2C10%2C0%2C0&s=', $url);
    }

    public function testGlideWatermarksNeedTheWatermarksOption(): void
    {
        $this->expectException(InvalidTransformationException::class);
        $this->expectExceptionMessage('The "mark" modifier needs watermarks: set the "watermarks" option');

        $this->glide()->url(new Image(path: 'a.jpg'), new ImageTransformation(modifiers: ['mark' => 'logo.png']), self::CONTEXT);
    }

    public function testGlideAcceptsWatermarksOnceConfigured(): void
    {
        $url = $this->glide(watermarks: __DIR__)->url(new Image(path: 'a.jpg'), new ImageTransformation(modifiers: ['mark' => 'logo.png']), self::CONTEXT);

        self::assertStringContainsString('mark=logo.png', $url);
    }

    public function testGlidePublicCacheStoresModifiersInTheFilename(): void
    {
        $url = $this->glide(publicCache: true)->url(new Image(path: 'a.jpg'), new ImageTransformation(width: 10, modifiers: ['flip' => 'h']), self::CONTEXT);

        self::assertStringStartsWith('/image/glide/fs/a.jpg/flip_h%2Cw_10.jpg?s=', $url);
    }

    public function testGlidePublicCacheRejectsValuesAFilenameCannotHold(): void
    {
        $this->expectException(InvalidTransformationException::class);
        $this->expectExceptionMessage('The "crop" modifier value "10,10,0,0" cannot be stored in a public-cache filename');

        $this->glide(publicCache: true)->url(new Image(path: 'a.jpg'), new ImageTransformation(modifiers: ['crop' => '10,10,0,0']), self::CONTEXT);
    }

    public function testGlideReservesModifiersAsRouteParameters(): void
    {
        $this->expectExceptionMessage('the parameters "flip" are reserved for the image transformation');

        $this->glide()->url(new Image(path: 'a.jpg'), new ImageTransformation(width: 10), [...self::CONTEXT, 'route' => 'picasso_image', 'route_parameters' => ['flip' => 'x']]);
    }

    private function glide(bool $publicCache = false, ?string $watermarks = null): GlideTransformer
    {
        $routes = new RouteCollection();
        $routes->add('picasso_image', new Route('/image/{transformer}/{loader}/{path}', requirements: ['path' => '.+']));

        return new GlideTransformer(
            new UrlGenerator($routes, new RequestContext()),
            new UrlAliases([], []),
            'modifiers-test-key_0123456789abcdef0123456789abcdef',
            sys_get_temp_dir() . '/picasso-modifiers-test',
            'gd',
            null,
            $publicCache,
            watermarks: $watermarks,
        );
    }
}
