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

namespace Silarhi\PicassoBundle\Tests\Fixtures;

use function class_exists;
use function enum_exists;
use function interface_exists;
use function trait_exists;

/**
 * Hides an installed class from the autoloader, to test an application that lacks
 * the optional package defining it.
 *
 * Every autoloader is replaced by one that refuses this class and delegates any
 * other to them. Hiding cannot be undone, and a class already loaded stays visible:
 * call it first thing in a test that runs in its own process (#[RunInSeparateProcess]).
 *
 * Such tests require PHPUnit 12+: with the lowest dependencies (PHPUnit 11), PHP
 * 8.4+ prints deprecations to stderr while the process boots, before any test
 * code, and PHPUnit fails a process writing to stderr, or blocks once the pipe
 * is full.
 */
trait HidesFromAutoloadingTrait
{
    /**
     * @param class-string $class
     */
    private static function hideFromAutoloading(string $class): void
    {
        $loaders = spl_autoload_functions();
        foreach ($loaders as $loader) {
            spl_autoload_unregister($loader);
        }

        spl_autoload_register(static function (string $name) use ($class, $loaders): void {
            if ($name === $class) {
                return;
            }

            foreach ($loaders as $loader) {
                $loader($name);
                if (class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false) || enum_exists($name, false)) {
                    return;
                }
            }
        });

        self::assertFalse(class_exists($class) || interface_exists($class), $class . ' was loaded before it could be hidden.');
    }
}
