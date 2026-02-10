<?php

namespace Silarhi\PicassoBundle\Loader;

use Silarhi\PicassoBundle\Dto\LoaderContext;

class FileLoader implements LoaderInterface
{
    public function __construct(
        private readonly string $baseDirectory,
    ) {
    }

    public function resolvePath(LoaderContext $context): string
    {
        return ltrim($context->getSourceAsString(), '/');
    }

    public function getDimensions(LoaderContext $context): ?array
    {
        $absolutePath = rtrim($this->baseDirectory, '/').'/'.ltrim($context->getSourceAsString(), '/');

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
