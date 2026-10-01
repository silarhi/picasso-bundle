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

namespace Silarhi\PicassoBundle\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\InvalidImageReferenceException;
use Silarhi\PicassoBundle\Loader\ChainLoader;
use Silarhi\PicassoBundle\Loader\FilesystemLoader;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Source\ImageSourceInterface;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;

class ChainLoaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures';

    public function testLoadsAPathWithTheFirstLoaderHoldingIt(): void
    {
        $chain = $this->createChain([
            'entities' => new FilesystemLoader(self::FIXTURES . '/Entity'),
            'fixtures' => new FilesystemLoader(self::FIXTURES),
        ]);

        $image = $chain->load(new ImageReference('photo.jpg'));

        self::assertSame('fixtures', $image->loader);
        self::assertSame('photo.jpg', $image->path);
        self::assertNotNull($image->resolveStream());
    }

    public function testLoadsAPathNoLoaderHoldsWithTheFirstLoader(): void
    {
        $chain = $this->createChain([
            'entities' => new FilesystemLoader(self::FIXTURES . '/Entity'),
            'fixtures' => new FilesystemLoader(self::FIXTURES),
        ]);

        $image = $chain->load(new ImageReference('missing.jpg'));

        self::assertSame('entities', $image->loader);
        self::assertSame('missing.jpg', $image->path);
    }

    public function testLoadsAnEntityWithTheFirstLoaderAcceptingIt(): void
    {
        $reference = new ImageReference(context: ['entity' => new stdClass()]);
        $accepted = new Image(path: 'avatars/me.jpg', width: 10, height: 20, mimeType: 'image/jpeg');
        $source = $this->createMock(ImageSourceInterface::class);
        // An entity reference is the accepting loader's: its source is not asked
        $source->expects(self::never())->method('exists');

        $chain = $this->createChain([
            'products' => $this->createLoader(new InvalidImageReferenceException('No field uses mapping "products".')),
            'avatars' => $this->createLoader($accepted, $source),
        ]);

        self::assertEquals(new Image('avatars/me.jpg', null, 10, 20, 'image/jpeg', 'avatars'), $chain->load($reference, true));
    }

    public function testThrowsWhenNoLoaderAcceptsTheReference(): void
    {
        $chain = $this->createChain([
            'products' => $this->createLoader(new InvalidImageReferenceException('No field uses mapping "products".')),
            'avatars' => $this->createLoader(new InvalidImageReferenceException('No field uses mapping "avatars".')),
        ]);

        $this->expectException(InvalidImageReferenceException::class);
        $this->expectExceptionMessage('No loader of chain "chain" (products, avatars) accepts this image reference.');

        $chain->load(new ImageReference(context: ['entity' => new stdClass()]));
    }

    /**
     * @param array<string, ServableLoaderInterface> $loaders
     */
    private function createChain(array $loaders): ChainLoader
    {
        return new ChainLoader(
            'chain',
            new ServiceLocator(array_map(static fn (ServableLoaderInterface $loader): callable => static fn (): ServableLoaderInterface => $loader, $loaders)),
            array_keys($loaders),
        );
    }

    private function createLoader(Image|InvalidImageReferenceException $result, ?ImageSourceInterface $source = null): ServableLoaderInterface
    {
        $loader = self::createStub(ServableLoaderInterface::class);
        $result instanceof Image ? $loader->method('load')->willReturn($result) : $loader->method('load')->willThrowException($result);
        $loader->method('getSource')->willReturn($source ?? self::createStub(ImageSourceInterface::class));

        return $loader;
    }
}
