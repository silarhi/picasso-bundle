<?php

namespace Silarhi\PicassoBundle\Resolver;

use Silarhi\PicassoBundle\Dto\ResolvedImage;
use Symfony\Component\AssetMapper\AssetMapperInterface;

class AssetMapperResolver implements ImageResolverInterface
{
    public function __construct(
        private readonly AssetMapperInterface $assetMapper,
    ) {
    }

    public function resolve(string $source, array $context = []): ResolvedImage
    {
        $asset = $this->assetMapper->getAsset($source);

        if ($asset === null) {
            throw new \InvalidArgumentException(sprintf('Asset "%s" not found in AssetMapper.', $source));
        }

        $path = ltrim($source, '/');
        $width = null;
        $height = null;

        if ($asset->sourcePath !== null && is_file($asset->sourcePath)) {
            $info = @getimagesize($asset->sourcePath);
            if ($info !== false) {
                $width = $info[0];
                $height = $info[1];
            }
        }

        return new ResolvedImage($path, $width, $height);
    }
}
