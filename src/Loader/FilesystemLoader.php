<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;

class FilesystemLoader implements ServableLoaderInterface
{
    public function __construct(
        private readonly string $baseDirectory,
    ) {
    }

    public function load(ImageReference $reference, bool $withMetadata = true): Image
    {
        $path = ltrim($reference->path ?? '', '/');

        if (!$withMetadata) {
            return new Image(path: $path);
        }

        $width = null;
        $height = null;
        $mimeType = null;

        $absolutePath = rtrim($this->baseDirectory, '/').'/'.$path;

        if (is_file($absolutePath)) {
            $info = @getimagesize($absolutePath);
            if ($info !== false) {
                $width = $info[0];
                $height = $info[1];
                $mimeType = $info['mime'] ?? null;
            }
        }

        return new Image(
            path: $path,
            width: $width,
            height: $height,
            mimeType: $mimeType,
        );
    }

    public function getSource(): object|string
    {
        return $this->baseDirectory;
    }
}
