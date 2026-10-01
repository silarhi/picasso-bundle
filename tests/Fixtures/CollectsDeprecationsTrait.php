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

/**
 * Collects the user deprecations a callable triggers.
 *
 * trigger_deprecation() silences them with "@", and PHPUnit 11 leaves silenced
 * deprecations out of expectUserDeprecationMessage(): an error handler of our
 * own sees them on every supported PHPUnit version.
 */
trait CollectsDeprecationsTrait
{
    /**
     * @template T
     *
     * @param callable(): T $callable
     *
     * @return array{T, list<string>}
     */
    private static function collectDeprecations(callable $callable): array
    {
        $deprecations = [];
        set_error_handler(static function (int $type, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $result = $callable();
        } finally {
            restore_error_handler();
        }

        return [$result, $deprecations];
    }
}
