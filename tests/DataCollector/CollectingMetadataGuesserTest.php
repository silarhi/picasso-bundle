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

namespace Silarhi\PicassoBundle\Tests\DataCollector;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\DataCollector\CollectingMetadataGuesser;
use Silarhi\PicassoBundle\DataCollector\PicassoDataCollector;
use Silarhi\PicassoBundle\Service\MetadataGuesserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CollectingMetadataGuesserTest extends TestCase
{
    public function testGuessForwardsToInnerAndRecordsCall(): void
    {
        $inner = $this->createMock(MetadataGuesserInterface::class);
        $stream = fopen('php://memory', 'r');
        self::assertNotFalse($stream);

        $inner->expects(self::once())
            ->method('guess')
            ->with($stream, 'filesystem:hero.jpg')
            ->willReturn(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']);

        $collector = new PicassoDataCollector();
        $decorator = new CollectingMetadataGuesser($inner, $collector);

        $result = $decorator->guess($stream, 'filesystem:hero.jpg');

        self::assertSame(['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg'], $result);

        $collector->collect(new Request(), new Response());
        $entries = $collector->getMetadata();
        self::assertCount(1, $entries);
        self::assertSame('filesystem:hero.jpg', $entries[0]->key);
        self::assertSame(1920, $entries[0]->width);
        self::assertSame(1080, $entries[0]->height);
        self::assertSame('image/jpeg', $entries[0]->mimeType);

        fclose($stream);
    }

    public function testGuessWithoutIdentifierUsesAnonymousKey(): void
    {
        $inner = $this->createMock(MetadataGuesserInterface::class);
        $inner->method('guess')->willReturn(['width' => null, 'height' => null, 'mimeType' => null]);

        $collector = new PicassoDataCollector();
        $decorator = new CollectingMetadataGuesser($inner, $collector);

        $stream = fopen('php://memory', 'r');
        self::assertNotFalse($stream);
        $decorator->guess($stream);
        fclose($stream);

        $collector->collect(new Request(), new Response());
        $entries = $collector->getMetadata();
        self::assertCount(1, $entries);
        self::assertSame('(anonymous)', $entries[0]->key);
    }
}
