<?php

declare(strict_types=1);

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageReference;

class FilesystemLoader implements ServableLoaderInterface
{
    /** @var list<string> */
    private readonly array $paths;

    /**
     * @param list<string> $paths
     */
    public function __construct(array $paths)
    {
        $this->paths = $paths;
    }

    public function load(ImageReference $reference, bool $withMetadata = false): Image
    {
        $path = ltrim($reference->path ?? '', '/');

        foreach ($this->paths as $basePath) {
            $absolutePath = rtrim($basePath, '/').'/'.$path;

            if (!is_file($absolutePath)) {
                continue;
            }

            $stream = @fopen($absolutePath, 'r') ?: null;
            $width = null;
            $height = null;
            $mimeType = null;
            $metadata = [];

            if (\count($this->paths) > 1) {
                $metadata['_source'] = $basePath;
            }

            if ($withMetadata) {
                $info = @getimagesize($absolutePath);

                if (false !== $info) {
                    $width = $info[0];
                    $height = $info[1];
                    $mimeType = $info['mime'];
                }
            }

            return new Image(path: $path, stream: $stream, width: $width, height: $height, mimeType: $mimeType, metadata: $metadata);
        }

        return new Image(path: $path);
    }

    public function getSource(): string
    {
        return $this->paths[0] ?? '';
    }
}
