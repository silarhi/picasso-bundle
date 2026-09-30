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

namespace Silarhi\PicassoBundle\Tests\Transformer\Stub;

use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

/**
 * A local cache whose writes fail the way an object store rejects the loser of
 * two concurrent writes of the same key.
 */
final class RacyCacheAdapter extends LocalFilesystemAdapter
{
    public int $writeAttempts = 0;

    public function __construct(string $location, private readonly bool $writesBeforeFailing)
    {
        parent::__construct($location);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        ++$this->writeAttempts;

        if ($this->writesBeforeFailing) {
            parent::write($path, $contents, $config);
        }

        throw UnableToWriteFile::atLocation($path, 'A conflicting conditional operation is currently in progress against this resource.');
    }
}
