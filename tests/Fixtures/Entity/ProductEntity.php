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

namespace Silarhi\PicassoBundle\Tests\Fixtures\Entity;

use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Attribute\Uploadable;
use Vich\UploaderBundle\Mapping\Attribute\UploadableField;

#[Uploadable]
class ProductEntity
{
    #[UploadableField(
        mapping: 'product_image',
        fileNameProperty: 'imageName',
        mimeType: 'mimeType',
        dimensions: 'dimensions',
    )]
    public ?File $imageFile = null;

    public ?string $imageName = null;

    public mixed $mimeType = null;

    public mixed $dimensions = null;
}
