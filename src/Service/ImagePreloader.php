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

namespace Silarhi\PicassoBundle\Service;

use function count;
use function in_array;

use Psr\Link\EvolvableLinkProviderInterface;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Dto\ImageSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\WebLink\GenericLinkProvider;
use Symfony\Component\WebLink\Link;

/**
 * Preloads priority images.
 *
 * Adds a `rel=preload` link to the main request, which symfony/web-link sends as a `Link` header (and as 103 Early
 * Hints when the application sends them): the browser starts fetching the image before it parses the `<picture>`.
 * Only registered when symfony/web-link is installed and the `preload` option is on.
 *
 * @internal
 */
final readonly class ImagePreloader
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function preload(ImageRenderData $data): void
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request || null === $data->fallbackSrc) {
            return;
        }

        $links = $request->attributes->get('_links');
        $links = $links instanceof EvolvableLinkProviderInterface ? $links : new GenericLinkProvider();

        // Art-directed sources come first in the <picture>: each media query preloads its first source, and the
        // image's own sources only apply when none of those queries matches
        $mediaQueries = [];
        foreach ($data->sources as $source) {
            if (null !== $source->media && !in_array($source->media, $mediaQueries, true)) {
                $mediaQueries[] = $source->media;
                $links = $links->withLink($this->sourceLink($source, $source->sizes ?? $data->sizes)->withAttribute('media', $source->media));
            }
        }

        // Preload what the <picture> picks first: its first <source>, typed so that browsers unable to decode that
        // format skip the preload instead of downloading a file the <picture> will not use
        $source = array_values(array_filter($data->sources, static fn (ImageSource $source): bool => null === $source->media))[0] ?? null;
        if (null !== $source) {
            $link = $this->sourceLink($source, $data->sizes);
        } else {
            $link = new Link(Link::REL_PRELOAD, $data->fallbackSrc);
            if (null !== $data->fallbackSrcset) {
                $link = $link->withAttribute('imagesrcset', $data->fallbackSrcset);
            }
            $link = $this->withImageAttributes($link, $data->sizes);
        }

        $otherwise = $this->negate($mediaQueries);
        if (null !== $otherwise) {
            $link = $link->withAttribute('media', $otherwise);
        }

        $request->attributes->set('_links', $links->withLink($link));
    }

    private function sourceLink(ImageSource $source, ?string $sizes): Link
    {
        // The srcset's first URL, in the source's format, for browsers ignoring imagesrcset
        $link = (new Link(Link::REL_PRELOAD, explode(' ', $source->srcset, 2)[0]))
            ->withAttribute('type', $source->type)
            ->withAttribute('imagesrcset', $source->srcset);

        return $this->withImageAttributes($link, $sizes);
    }

    private function withImageAttributes(Link $link, ?string $sizes): Link
    {
        $link = $link->withAttribute('as', 'image')->withAttribute('fetchpriority', 'high');

        return null !== $sizes ? $link->withAttribute('imagesizes', $sizes) : $link;
    }

    /**
     * A media query matching when none of the given ones does, so the image's own preload is skipped where an
     * art-directed source is picked instead. Only media conditions ("(max-width: 640px)") can be negated as a whole:
     * a media type or a list leaves the image's own preload unconditional.
     *
     * @param list<string> $mediaQueries
     */
    private function negate(array $mediaQueries): ?string
    {
        if ([] === $mediaQueries) {
            return null;
        }

        foreach ($mediaQueries as $mediaQuery) {
            if (!str_starts_with(trim($mediaQuery), '(') || str_contains($mediaQuery, ',')) {
                return null;
            }
        }

        // "not" negates the whole query that follows it; several are joined with Media Queries 4's "or"
        return 1 === count($mediaQueries)
            ? 'not all and ' . trim($mediaQueries[0])
            : 'not all and (' . implode(' or ', array_map(static fn (string $query): string => '(' . trim($query) . ')', $mediaQueries)) . ')';
    }
}
