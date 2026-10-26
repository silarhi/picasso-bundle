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

use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Images of the application's assets are rendered by logical path and served by Glide through AssetMapper.
 */
class AssetMapperEndToEndTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return AssetMapperKernel::class;
    }

    public function testRendersAndServesAnAssetByItsLogicalPath(): void
    {
        $data = $this->helper()->imageData(src: 'images/photo.jpg', sizes: '100vw');

        // Dimensions are read from the asset, as for a filesystem loader
        self::assertNotNull($data->width);
        self::assertNotNull($data->fallbackSrc);
        self::assertStringStartsWith('/image/glide/asset_mapper/images/photo.jpg?', $data->fallbackSrc);

        $response = self::$kernel?->handle(Request::create($data->fallbackSrc));
        assert(null !== $response);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
    }

    public function testAnUnmappedPathIsNotFound(): void
    {
        $url = $this->helper()->imageUrl('images/../../composer.json', width: 10);

        $response = self::$kernel?->handle(Request::create($url));
        assert(null !== $response);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testAChainRendersWithTheAssetMapperWhenItHoldsTheImage(): void
    {
        $data = $this->helper()->imageData(src: 'images/photo.jpg', width: 10, height: 10, loader: 'all');

        self::assertSame('asset_mapper', $data->loader);
    }

    public function testResolvesMetadataByDefault(): void
    {
        $registry = self::getContainer()->get('picasso.loader_registry');
        assert($registry instanceof LoaderRegistry);

        self::assertTrue($registry->getResolveMetadata('asset_mapper'));
    }

    private function helper(): ImageHelperInterface
    {
        self::bootKernel();
        $helper = self::getContainer()->get(ImageHelperInterface::class);
        assert($helper instanceof ImageHelperInterface);

        return $helper;
    }
}
