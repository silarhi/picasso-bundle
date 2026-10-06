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
use Silarhi\PicassoBundle\Loader\ImageLoaderInterface;

#[AsImageLoader('configured', defaultPlaceholder: 'stub', defaultTransformer: 'stub', resolveMetadata: false, urlAlias: 'cfg', private: true)]
final class StubConfiguredAttributeLoader implements ImageLoaderInterface
{
    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        return new Image(path: 'configured/' . ($reference->path ?? ''));
    }
}
