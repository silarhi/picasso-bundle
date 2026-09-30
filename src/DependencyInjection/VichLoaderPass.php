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

use function count;
use function is_array;
use function is_string;

use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;

use function sprintf;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Binds each vich loader to one VichUploader mapping and its upload destination.
 *
 * The mapping is the loader's "mapping" option, else the loader name when it is
 * a mapping, else the only mapping configured. It runs as a compiler pass because
 * the VichUploader configuration is only known once every extension is loaded.
 *
 * @internal
 */
final class VichLoaderPass implements CompilerPassInterface
{
    public const TAG = '.picasso.vich_loader';

    public function process(ContainerBuilder $container): void
    {
        $loaders = $container->findTaggedServiceIds(self::TAG);
        if ([] === $loaders) {
            return;
        }

        $mappings = $container->hasParameter('vich_uploader.mappings') ? $container->getParameter('vich_uploader.mappings') : null;

        foreach ($loaders as $id => $tags) {
            foreach ($tags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                $loader = is_string($tag['loader'] ?? null) ? $tag['loader'] : $id;

                if (!is_array($mappings)) {
                    throw new InvalidConfigurationException(sprintf('Loader "%s" is a vich loader, but VichUploaderBundle is not registered in your kernel.', $loader));
                }

                $mapping = $this->resolveMapping($loader, is_string($tag['mapping'] ?? null) ? $tag['mapping'] : null, $mappings);
                $uploadDestination = is_array($mappings[$mapping]) ? ($mappings[$mapping]['upload_destination'] ?? null) : null;

                if (!is_string($uploadDestination)) {
                    throw new InvalidConfigurationException(sprintf('Loader "%s": the VichUploader mapping "%s" has no "upload_destination".', $loader, $mapping));
                }

                $container->getDefinition($id)
                    ->replaceArgument(2, $mapping)
                    ->replaceArgument(3, $uploadDestination);
            }
        }
    }

    /**
     * @param array<mixed> $mappings VichUploader mappings, keyed by name
     */
    private function resolveMapping(string $loader, ?string $configured, array $mappings): string
    {
        $available = array_map(strval(...), array_keys($mappings));

        if (null !== $configured) {
            if (!isset($mappings[$configured])) {
                throw new InvalidConfigurationException(sprintf('Loader "%s" serves the VichUploader mapping "%s", which does not exist. %s', $loader, $configured, $this->describe($available)));
            }

            return $configured;
        }

        if (isset($mappings[$loader])) {
            return $loader;
        }

        if (1 === count($available)) {
            return $available[0];
        }

        if ([] === $available) {
            throw new InvalidConfigurationException(sprintf('Loader "%s" is a vich loader, but VichUploader has no mappings configured.', $loader));
        }

        throw new InvalidConfigurationException(sprintf('Loader "%s" does not say which VichUploader mapping it serves. Set its "mapping" option, or name the loader after the mapping. %s A vich loader serves a single mapping: declare one loader per mapping.', $loader, $this->describe($available)));
    }

    /**
     * @param list<string> $available
     */
    private function describe(array $available): string
    {
        return [] === $available
            ? 'VichUploader has no mappings configured.'
            : sprintf('Available mappings: "%s".', implode('", "', $available));
    }
}
