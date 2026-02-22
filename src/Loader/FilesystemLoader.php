<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;

class FilesystemLoader implements ServableLoaderInterface
{
    public function __construct(
        private readonly string $baseDirectory,
    ) {
    }

    public function load(ImageReference $reference): Image
    {
        $path = ltrim($reference->path ?? '', '/');
        $absolutePath = rtrim($this->baseDirectory, '/').'/'.$path;
        $stream = is_file($absolutePath) ? @fopen($absolutePath, 'r') : null;

        return new Image(path: $path, stream: $stream ?: null);
    }

    public function getSource(): string
    {
        return $this->baseDirectory;
    }
}
