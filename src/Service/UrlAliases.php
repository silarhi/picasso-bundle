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

/**
 * The public names of loaders and transformers in image URLs.
 *
 * A loader or transformer may set a "url_alias" that stands for its name in the
 * URLs the bundle generates and in public-cache keys, e.g. "/image/g/p/photo.jpg"
 * instead of "/image/glide/product_image/photo.jpg". Everything else (contexts,
 * registries, purges) keeps using names: aliases only exist on the wire.
 *
 * Aliases are collected and checked by UrlAliasPass, so an alias never collides
 * with another alias or name of the same kind.
 */
final readonly class UrlAliases
{
    /** @var array<string, string> Alias => loader name */
    private array $loaderNames;

    /** @var array<string, string> Alias => transformer name */
    private array $transformerNames;

    /**
     * @param array<string, string> $loaderAliases      Loader name => alias
     * @param array<string, string> $transformerAliases Transformer name => alias
     */
    public function __construct(
        private array $loaderAliases,
        private array $transformerAliases,
    ) {
        $this->loaderNames = array_flip($loaderAliases);
        $this->transformerNames = array_flip($transformerAliases);
    }

    /**
     * The URL path segment naming a loader: its alias, else its name.
     */
    public function loaderSegment(string $loader): string
    {
        return $this->loaderAliases[$loader] ?? $loader;
    }

    /**
     * The URL path segment naming a transformer: its alias, else its name.
     */
    public function transformerSegment(string $transformer): string
    {
        return $this->transformerAliases[$transformer] ?? $transformer;
    }

    /**
     * The loader a URL path segment names: the loader of an alias, else the segment itself.
     *
     * A name keeps working once its loader has an alias, so URLs minted before
     * the alias was set are still served.
     */
    public function resolveLoader(string $segment): string
    {
        return $this->loaderNames[$segment] ?? $segment;
    }

    /**
     * The transformer a URL path segment names: the transformer of an alias, else the segment itself.
     *
     * A name keeps working once its transformer has an alias, so URLs minted
     * before the alias was set are still served.
     */
    public function resolveTransformer(string $segment): string
    {
        return $this->transformerNames[$segment] ?? $segment;
    }
}
