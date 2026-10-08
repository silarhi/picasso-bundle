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

namespace Silarhi\PicassoBundle\Tests\Functional\Stub;

use Silarhi\PicassoBundle\Attribute\AsImageLoader;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\ImageSourceUnavailableException;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Source\ImageSourceInterface;

use function sprintf;

/**
 * A loader whose storage is down: its source can neither tell whether an image
 * exists nor read one.
 */
#[AsImageLoader('down')]
final class StubUnavailableLoader implements ServableLoaderInterface
{
    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        return new Image(path: $reference->path);
    }

    public function getSource(): ImageSourceInterface
    {
        return new class implements ImageSourceInterface {
            public function exists(string $path): bool
            {
                throw new ImageSourceUnavailableException('Storage down.');
            }

            public function readStream(string $path): never
            {
                throw new ImageSourceUnavailableException(sprintf('Image "%s" could not be read: its storage is unavailable.', $path));
            }
        };
    }
}
