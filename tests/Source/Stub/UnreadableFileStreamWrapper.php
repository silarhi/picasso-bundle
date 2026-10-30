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

namespace Silarhi\PicassoBundle\Tests\Source\Stub;

use function in_array;

/**
 * A stream wrapper whose every path is a regular file that cannot be opened, as a
 * file the process may not read. Unlike chmod(), it also holds when tests run as root.
 */
final class UnreadableFileStreamWrapper
{
    public const PROTOCOL = 'picasso-unreadable';

    /** @var resource|null */
    public $context;

    public static function register(): void
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    /**
     * @return array{mode: int, size: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0o100644, 'size' => 0];
    }
}
