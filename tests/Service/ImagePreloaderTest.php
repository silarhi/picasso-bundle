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

namespace Silarhi\PicassoBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Link\LinkInterface;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Silarhi\PicassoBundle\Service\ImagePreloader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\WebLink\GenericLinkProvider;
use Symfony\Component\WebLink\Link;

class ImagePreloaderTest extends TestCase
{
    public function testPreloadsTheFirstSourceOfAPriorityImage(): void
    {
        $request = new Request();
        $this->preloader($request)->preload($this->renderData([
            new ImageSource('image/avif', '/a-640.avif 640w, /a-1080.avif 1080w'),
            new ImageSource('image/webp', '/a-640.webp 640w, /a-1080.webp 1080w'),
        ]));

        $link = $this->onlyLink($request);
        self::assertSame('/a-640.avif', $link->getHref());
        self::assertSame(['preload'], $link->getRels());
        self::assertSame([
            'type' => 'image/avif',
            'imagesrcset' => '/a-640.avif 640w, /a-1080.avif 1080w',
            'as' => 'image',
            'fetchpriority' => 'high',
            'imagesizes' => '100vw',
        ], $link->getAttributes());
    }

    public function testPreloadsEachArtDirectedMediaQuery(): void
    {
        $request = new Request();
        $this->preloader($request)->preload($this->renderData([
            new ImageSource('image/webp', '/m.webp 320w', media: '(max-width: 640px)', sizes: '50vw'),
            new ImageSource('image/jpeg', '/m.jpg 320w', media: '(max-width: 640px)', sizes: '50vw'),
            new ImageSource('image/webp', '/p.webp 320w', media: '(orientation: portrait)'),
            new ImageSource('image/webp', '/a-640.webp 640w'),
        ]));

        $links = $this->links($request);

        self::assertSame(['/m.webp', '/p.webp', '/a-640.webp'], array_map(static fn (LinkInterface $link): string => $link->getHref(), $links));
        self::assertSame('(max-width: 640px)', $links[0]->getAttributes()['media']);
        self::assertSame('50vw', $links[0]->getAttributes()['imagesizes'], 'an art-directed source keeps its own sizes');
        self::assertSame('100vw', $links[1]->getAttributes()['imagesizes'], 'else the image\'s');
        self::assertSame('not all and (((max-width: 640px)) or ((orientation: portrait)))', $links[2]->getAttributes()['media']);
    }

    public function testLeavesTheImagePreloadUnconditionalWhenAQueryCannotBeNegated(): void
    {
        $request = new Request();
        $this->preloader($request)->preload($this->renderData([
            new ImageSource('image/webp', '/m.webp 320w', media: 'screen and (max-width: 640px)'),
            new ImageSource('image/webp', '/a-640.webp 640w'),
        ]));

        $links = $this->links($request);

        self::assertCount(2, $links);
        self::assertArrayNotHasKey('media', $links[1]->getAttributes());
    }

    public function testPreloadsTheImgWhenThereIsNoSource(): void
    {
        $request = new Request();
        $this->preloader($request)->preload($this->renderData([], sizes: null));

        $link = $this->onlyLink($request);
        self::assertSame('/a-1080.jpg', $link->getHref());
        self::assertSame([
            'imagesrcset' => '/a-640.jpg 640w, /a-1080.jpg 1080w',
            'as' => 'image',
            'fetchpriority' => 'high',
        ], $link->getAttributes());
    }

    public function testPreloadsAnUnoptimizedImage(): void
    {
        $request = new Request();
        $data = new ImageRenderData(
            fallbackSrc: '/hero.jpg',
            fallbackSrcset: null,
            sources: [],
            placeholderUri: null,
            width: null,
            height: null,
            loading: 'eager',
            fetchPriority: 'high',
            sizes: null,
            unoptimized: true,
        );

        $this->preloader($request)->preload($data);

        $link = $this->onlyLink($request);
        self::assertSame('/hero.jpg', $link->getHref());
        self::assertSame(['as' => 'image', 'fetchpriority' => 'high'], $link->getAttributes());
    }

    public function testKeepsTheLinksAlreadyAdded(): void
    {
        $request = new Request();
        $request->attributes->set('_links', (new GenericLinkProvider())->withLink(new Link(Link::REL_PRELOAD, '/app.css')));
        $this->preloader($request)->preload($this->renderData([]));

        self::assertSame(['/app.css', '/a-1080.jpg'], array_map(
            static fn (LinkInterface $link): string => $link->getHref(),
            $this->preloads($request),
        ));
    }

    public function testDoesNothingWithoutARequest(): void
    {
        $this->expectNotToPerformAssertions();

        (new ImagePreloader(new RequestStack()))->preload($this->renderData([]));
    }

    public function testDoesNothingWithoutAnImage(): void
    {
        $request = new Request();
        $data = new ImageRenderData(
            fallbackSrc: null,
            fallbackSrcset: null,
            sources: [],
            placeholderUri: null,
            width: null,
            height: null,
            loading: 'eager',
            fetchPriority: 'high',
            sizes: null,
            unoptimized: true,
        );

        $this->preloader($request)->preload($data);

        self::assertFalse($request->attributes->has('_links'));
    }

    /**
     * @return list<LinkInterface>
     */
    private function links(Request $request): array
    {
        $provider = $request->attributes->get('_links');
        self::assertInstanceOf(GenericLinkProvider::class, $provider);

        $links = [];
        foreach ($provider->getLinks() as $link) {
            self::assertInstanceOf(LinkInterface::class, $link);
            $links[] = $link;
        }

        return $links;
    }

    private function preloader(Request $request): ImagePreloader
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new ImagePreloader($requestStack);
    }

    /**
     * @param list<ImageSource> $sources
     */
    private function renderData(array $sources, ?string $sizes = '100vw'): ImageRenderData
    {
        return new ImageRenderData(
            fallbackSrc: '/a-1080.jpg',
            fallbackSrcset: '/a-640.jpg 640w, /a-1080.jpg 1080w',
            sources: $sources,
            placeholderUri: null,
            width: 1080,
            height: 720,
            loading: 'eager',
            fetchPriority: 'high',
            sizes: $sizes,
            unoptimized: false,
        );
    }

    private function onlyLink(Request $request): LinkInterface
    {
        $preloads = $this->preloads($request);
        self::assertCount(1, $preloads);

        return $preloads[0];
    }

    /**
     * @return list<LinkInterface>
     */
    private function preloads(Request $request): array
    {
        $links = $request->attributes->get('_links');
        self::assertInstanceOf(GenericLinkProvider::class, $links);
        $preloads = [];
        foreach ($links->getLinks() as $link) {
            self::assertInstanceOf(LinkInterface::class, $link);
            $preloads[] = $link;
        }

        return $preloads;
    }
}
