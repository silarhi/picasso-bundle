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

namespace Silarhi\PicassoBundle\Command;

use function defined;
use function is_resource;
use function is_string;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * The image paths a command works on: its arguments, else one path per line of its input.
 *
 * @internal
 */
final class PathsInput
{
    /**
     * @return list<string>
     */
    public static function read(InputInterface $input): array
    {
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        if ([] !== $paths) {
            return $paths;
        }

        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        if (!is_resource($stream)) {
            // Without piped input, STDIN is the terminal: reading it would wait for the user
            if (!defined('STDIN') || stream_isatty(\STDIN)) {
                return [];
            }
            $stream = \STDIN;
        }

        $paths = [];
        while (is_string($line = fgets($stream))) {
            $line = trim($line);
            if ('' !== $line) {
                $paths[] = $line;
            }
        }

        return $paths;
    }
}
