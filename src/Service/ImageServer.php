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

namespace Silarhi\PicassoBundle\Service;

use function is_string;

use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\InvalidConfigurationException;
use Silarhi\PicassoBundle\Exception\UndecodableImageException;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;

use function sprintf;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves images from the application's own routes, e.g. routes guarded by
 * security voters, whose URLs are generated with the "route" option.
 *
 * Unlike the bundle's image route, the URL does not say which image to serve:
 * the controller does, by passing the image reference (typically the entity it
 * just authorized). The signature in the query still has to match it, so only
 * the transformations the application generated are rendered.
 */
final readonly class ImageServer
{
    public function __construct(
        private ImagePipeline $pipeline,
        private LoaderRegistry $loaderRegistry,
        private TransformerRegistry $transformerRegistry,
    ) {
    }

    /**
     * Serve the transformation the request asks for of an image.
     *
     * The response is marked "private, no-cache": browsers keep the image but check
     * with your route (and its voters) before reusing it, and shared caches never
     * store it. Change its headers on the returned response if needed.
     *
     * @param ImageReference|string $source      The image: a reference (e.g. holding the entity), or its path
     * @param string|null           $loader      The loader the URL was generated with; null for the default one
     * @param string|null           $transformer The transformer the URL was generated with; null for the loader's default
     *
     * @throws NotFoundHttpException         When the image is missing or cannot be decoded, or the signature does not match
     * @throws InvalidConfigurationException When the loader cannot be served or the transformer cannot serve
     */
    public function serve(Request $request, ImageReference|string $source, ?string $loader = null, ?string $transformer = null): Response
    {
        $loaderName = $this->pipeline->resolveLoaderName($loader);
        // Resolved like when rendering: URLs are signed with that transformer's key, so both must agree
        $transformerName = $this->pipeline->resolveTransformerName($transformer, $loaderName);

        $imageTransformer = $this->transformerRegistry->get($transformerName);
        if (!$imageTransformer instanceof LocalTransformerInterface) {
            throw new InvalidConfigurationException(sprintf('Transformer "%s" cannot serve images: only local transformers (e.g. Glide) can.', $transformerName));
        }

        $image = $this->pipeline->load(is_string($source) ? new ImageReference($source) : $source, $loaderName);
        // A delegating loader (e.g. a chain) names the loader that loaded the image, which serves it
        $servingLoaderName = $image->loader ?? $loaderName;
        $servingLoader = $this->loaderRegistry->get($servingLoaderName);
        if (!$servingLoader instanceof ServableLoaderInterface) {
            throw new InvalidConfigurationException(sprintf('Loader "%s" cannot be served: it does not implement ServableLoaderInterface.', $servingLoaderName));
        }

        if (null === $image->path || '' === $image->path) {
            throw new NotFoundHttpException('Image not found.');
        }

        try {
            $response = $imageTransformer->serve($servingLoader, $image->path, $request, [
                'transformer' => $transformerName,
                'loader' => $servingLoaderName,
            ]);
        } catch (ImageNotFoundException|UndecodableImageException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        // Transformers answer for public images (Glide: public, a year); access to
        // these is decided per request, so no cache may reuse them unchecked.
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->remove('Expires');

        return $response;
    }
}
