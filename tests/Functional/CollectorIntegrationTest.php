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

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\DataCollector\CollectingImageHelper;
use Silarhi\PicassoBundle\DataCollector\CollectingMetadataGuesser;
use Silarhi\PicassoBundle\DataCollector\PicassoDataCollector;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class CollectorIntegrationTest extends TestCase
{
    private static KernelInterface $kernel;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new CollectorKernel('test', true);
        self::$kernel->boot();

        $glideCache = self::$kernel->getCacheDir() . '/glide';
        if (!is_dir($glideCache)) {
            mkdir($glideCache, 0777, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
    }

    private function getTestContainer(): ContainerInterface
    {
        $container = self::$kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }

    public function testCollectorIsRegisteredWhenEnabled(): void
    {
        $container = $this->getTestContainer();

        self::assertTrue($container->has('picasso.data_collector'));
        self::assertInstanceOf(PicassoDataCollector::class, $container->get('picasso.data_collector'));
    }

    public function testImageHelperIsDecoratedByCollectingDecorator(): void
    {
        $container = $this->getTestContainer();

        $helper = $container->get('picasso.image_helper');
        self::assertInstanceOf(CollectingImageHelper::class, $helper);
        self::assertInstanceOf(ImageHelperInterface::class, $helper);
    }

    public function testMetadataGuesserIsDecoratedByCollectingDecorator(): void
    {
        $container = $this->getTestContainer();

        $guesser = $container->get('picasso.metadata_guesser');
        self::assertInstanceOf(CollectingMetadataGuesser::class, $guesser);
        self::assertInstanceOf(MetadataGuesserInterface::class, $guesser);
    }

    public function testImageUrlCallIsRecorded(): void
    {
        $container = $this->getTestContainer();
        $helper = $container->get('picasso.image_helper');
        self::assertInstanceOf(ImageHelperInterface::class, $helper);

        $collector = $container->get('picasso.data_collector');
        self::assertInstanceOf(PicassoDataCollector::class, $collector);

        $collector->reset();
        $helper->imageUrl('photo.jpg', width: 400);

        // After the decorator runs, the entry exists on the collector even
        // before collect() is invoked by the kernel.
        $collector->collect(new \Symfony\Component\HttpFoundation\Request(), new \Symfony\Component\HttpFoundation\Response());
        $urls = $collector->getUrls();
        self::assertCount(1, $urls);
        self::assertSame('photo.jpg', $urls[0]->src);
        self::assertSame(400, $urls[0]->width);
    }
}
