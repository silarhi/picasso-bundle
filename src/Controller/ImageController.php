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

namespace Silarhi\PicassoBundle\Controller;

use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;

use function sprintf;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ImageController
{
    public function __construct(
        private TransformerRegistry $transformers,
        private LoaderRegistry $loaders,
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
