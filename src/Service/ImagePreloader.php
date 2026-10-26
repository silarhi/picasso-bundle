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

use Psr\Link\EvolvableLinkProviderInterface;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
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

        // Preload what the <picture> picks first: its first <source>, typed so that browsers unable to decode that
        // format skip the preload instead of downloading a file the <picture> will not use
        $source = $data->sources[0] ?? null;
        if (null !== $source) {
            // The srcset's first URL, in the source's format, for browsers ignoring imagesrcset
            $link = (new Link('preload', explode(' ', $source->srcset, 2)[0]))
                ->withAttribute('type', $source->type)
                ->withAttribute('imagesrcset', $source->srcset);
        } else {
            $link = new Link('preload', $data->fallbackSrc);
            if (null !== $data->fallbackSrcset) {
                $link = $link->withAttribute('imagesrcset', $data->fallbackSrcset);
            }
        }

        $link = $link->withAttribute('as', 'image')->withAttribute('fetchpriority', 'high');
        if (null !== $data->sizes) {
            $link = $link->withAttribute('imagesizes', $data->sizes);
        }

        $links = $request->attributes->get('_links');
        $links = $links instanceof EvolvableLinkProviderInterface ? $links : new GenericLinkProvider();
        $request->attributes->set('_links', $links->withLink($link));
    }
}
