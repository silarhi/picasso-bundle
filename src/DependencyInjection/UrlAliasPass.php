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

namespace Silarhi\PicassoBundle\DependencyInjection;

use function is_array;
use function is_string;

use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;

use function sprintf;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects the "url_alias" of every loader and transformer into the URL aliases
 * service, from their service tags, so aliases set in the bundle configuration
 * and through #[AsImageLoader] / #[AsImageTransformer] are handled alike.
 *
 * @internal
 */
final class UrlAliasPass implements CompilerPassInterface
{
    public const SERVICE = 'picasso.url_aliases';

    /** What an alias may contain: it is a URL path segment and a public-cache key segment */
    private const ALIAS_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::SERVICE)) {
            return;
        }

        $container->getDefinition(self::SERVICE)
            ->setArgument(0, $this->collect($container, 'picasso.loader', 'Loader'))
            ->setArgument(1, $this->collect($container, 'picasso.transformer', 'Transformer'));
    }

    /**
     * @return array<string, string> Name => alias
     */
    private function collect(ContainerBuilder $container, string $tagName, string $kind): array
    {
        $names = [];
        $aliases = [];

        foreach ($container->findTaggedServiceIds($tagName) as $tags) {
            foreach ($tags as $tag) {
                if (!is_array($tag) || !is_string($tag['key'] ?? null)) {
                    continue;
                }

                $names[$tag['key']] = true;
                $alias = $tag['url_alias'] ?? null;
                if (null === $alias) {
                    continue;
                }

                if (!is_string($alias) || 1 !== preg_match(self::ALIAS_PATTERN, $alias)) {
                    throw new InvalidConfigurationException(sprintf('%s "%s": the "url_alias" must only contain letters, digits, "_" and "-".', $kind, $tag['key']));
                }

                $aliases[$tag['key']] = $alias;
            }
        }

        // Each URL segment must name a single loader (or transformer), whether it
        // is an alias or a name
        $owners = [];
        foreach ($aliases as $name => $alias) {
            if (isset($owners[$alias])) {
                throw new InvalidConfigurationException(sprintf('%s "%s": the "url_alias" "%s" is already the alias of %s "%s".', $kind, $name, $alias, lcfirst($kind), $owners[$alias]));
            }

            if ($alias !== $name && isset($names[$alias])) {
                throw new InvalidConfigurationException(sprintf('%s "%s": the "url_alias" "%s" is the name of another %s.', $kind, $name, $alias, lcfirst($kind)));
            }

            $owners[$alias] = $name;
        }

        return $aliases;
    }
}
