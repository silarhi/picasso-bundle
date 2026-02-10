<?php

namespace Silarhi\PicassoBundle\Resolver;

use Silarhi\PicassoBundle\Dto\ResolvedImage;

class FilesystemResolver implements ImageResolverInterface
{
    public function __construct(
        private readonly ?string $baseDirectory = null,
    ) {
    }

    public function resolve(string $source, array $context = []): ResolvedImage
    {
        $path = ltrim($source, '/');

        $width = null;
        $height = null;

        if ($this->baseDirectory !== null) {
            $absolutePath = rtrim($this->baseDirectory, '/').'/'.$path;

            if (is_file($absolutePath)) {
                $info = @getimagesize($absolutePath);
                if ($info !== false) {
                    $width = $info[0];
                    $height = $info[1];
                }
            }
        }

        return new ResolvedImage($path, $width, $height);
    }
}
