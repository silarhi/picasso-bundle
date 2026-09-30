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

/**
 * Two upload fields sharing one mapping.
 */
#[Uploadable]
class GalleryEntity
{
    #[UploadableField(mapping: 'product_image', fileNameProperty: 'coverName')]
    public ?File $coverFile = null;

    public ?string $coverName = null;

    #[UploadableField(mapping: 'product_image', fileNameProperty: 'thumbnailName')]
    public ?File $thumbnailFile = null;

    public ?string $thumbnailName = null;
}
