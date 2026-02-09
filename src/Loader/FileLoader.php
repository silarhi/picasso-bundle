<?php

namespace Silarhi\PicassoBundle\Loader;

class FileLoader implements LoaderInterface
{
    public function __construct(
        private readonly string $baseDirectory,
    ) {
    }

    public function resolvePath(string|object $source, ?string $field = null): string
    {
        return ltrim((string) $source, '/');
    }

    public function getDimensions(string|object $source, ?string $field = null): ?array
    {
        $absolutePath = rtrim($this->baseDirectory, '/').'/'.ltrim((string) $source, '/');

        if (!is_file($absolutePath)) {
            return null;
        }

        $info = @getimagesize($absolutePath);
        if ($info === false) {
            return null;
        }

        return [$info[0], $info[1]];
    }
}
