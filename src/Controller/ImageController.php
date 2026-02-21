<?php

namespace Silarhi\PicassoBundle\Controller;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageController
{
    public function __construct(
        private readonly ContainerInterface $transformers,
        private readonly ContainerInterface $loaders,
    ) {
    }

    public function __invoke(string $transformer, string $loader, string $path, Request $request): Response
    {
        if (!$this->transformers->has($transformer)) {
            throw new NotFoundHttpException(sprintf('Transformer "%s" not found.', $transformer));
        }

        $imageTransformer = $this->transformers->get($transformer);
        if (!$imageTransformer instanceof LocalTransformerInterface) {
            throw new NotFoundHttpException(sprintf('Transformer "%s" does not support serving.', $transformer));
        }

        if (!$this->loaders->has($loader)) {
            throw new NotFoundHttpException(sprintf('Loader "%s" not found.', $loader));
        }

        $imageLoader = $this->loaders->get($loader);
        if (!$imageLoader instanceof ServableLoaderInterface) {
            throw new NotFoundHttpException(sprintf('Loader "%s" does not support serving.', $loader));
        }

        return $imageTransformer->serve($imageLoader, $path, $request);
    }
}
