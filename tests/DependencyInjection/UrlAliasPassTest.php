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

namespace Silarhi\PicassoBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\DependencyInjection\UrlAliasPass;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class UrlAliasPassTest extends TestCase
{
    public function testAliasesAreCollectedFromTheTags(): void
    {
        $container = $this->process(
            loaders: ['product_image' => 'p', 'filesystem' => null],
            transformers: ['glide' => 'g'],
        );

        $definition = $container->getDefinition(UrlAliasPass::SERVICE);
        self::assertSame(['product_image' => 'p'], $definition->getArgument(0));
        self::assertSame(['glide' => 'g'], $definition->getArgument(1));
    }

    public function testAnAliasMayBeItsOwnName(): void
    {
        $container = $this->process(loaders: ['product_image' => 'product_image']);

        self::assertSame(['product_image' => 'product_image'], $container->getDefinition(UrlAliasPass::SERVICE)->getArgument(0));
    }

    public function testTagsWithoutAKeyAreIgnored(): void
    {
        // A service tagged by hand without a key is located by its service id, and an alias needs a name
        $container = $this->process(loaders: ['product_image' => 'p']);
        $container->register('app.loader')->addTag('picasso.loader', ['url_alias' => 'a']);

        (new UrlAliasPass())->process($container);

        self::assertSame(['product_image' => 'p'], $container->getDefinition(UrlAliasPass::SERVICE)->getArgument(0));
    }

    public function testAnAliasMustBeAUrlSegment(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "product_image": the "url_alias" must only contain letters, digits, "_" and "-".');

        $this->process(loaders: ['product_image' => 'p/i']);
    }

    public function testTwoLoadersCannotShareAnAlias(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Loader "user_avatar": the "url_alias" "p" is already the alias of loader "product_image".');

        $this->process(loaders: ['product_image' => 'p', 'user_avatar' => 'p']);
    }

    public function testAnAliasCannotBeTheNameOfAnotherTransformer(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Transformer "glide": the "url_alias" "imgix" is the name of another transformer.');

        $this->process(transformers: ['glide' => 'imgix', 'imgix' => null]);
    }

    public function testLoadersAndTransformersMayShareAnAlias(): void
    {
        // They name different URL segments
        $container = $this->process(loaders: ['product_image' => 'p'], transformers: ['picasso' => 'p']);

        self::assertSame(['picasso' => 'p'], $container->getDefinition(UrlAliasPass::SERVICE)->getArgument(1));
    }

    /**
     * @param array<string, string|null> $loaders      Name => alias
     * @param array<string, string|null> $transformers Name => alias
     */
    private function process(array $loaders = [], array $transformers = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(UrlAliasPass::SERVICE, new Definition(UrlAliases::class, [[], []]));

        foreach (['picasso.loader' => $loaders, 'picasso.transformer' => $transformers] as $tagName => $aliases) {
            foreach ($aliases as $name => $alias) {
                $tag = ['key' => $name];
                if (null !== $alias) {
                    $tag['url_alias'] = $alias;
                }
                $container->register($tagName . '.' . $name)->addTag($tagName, $tag);
            }
        }

        (new UrlAliasPass())->process($container);

        return $container;
    }
}
