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

use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Exception\LoaderNotFoundException;
use Silarhi\PicassoBundle\Exception\TransformerNotFoundException;
use Silarhi\PicassoBundle\Exception\UndecodableImageException;
use Silarhi\PicassoBundle\Loader\ServableLoaderInterface;
use Silarhi\PicassoBundle\Service\LoaderRegistry;
use Silarhi\PicassoBundle\Service\TransformerRegistry;
use Silarhi\PicassoBundle\Transformer\LocalTransformerInterface;

use function sprintf;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Stopwatch\Stopwatch;
use Throwable;

final readonly class ImageController
{
    /**
     * @param array<string, int> $errorMaxAges Per transformer name, how long (seconds) clients and CDNs may cache a 404
     */
    public function __construct(
        private TransformerRegistry $transformerRegistry,
        private LoaderRegistry $loaderRegistry,
        private ?Stopwatch $stopwatch = null,
        private array $errorMaxAges = [],
    ) {
    }

    public function __invoke(string $transformer, string $loader, string $path, Request $request): Response
    {
        if (!$this->transformerRegistry->has($transformer)) {
            throw new NotFoundHttpException(sprintf('Transformer "%s" not found.', $transformer), new TransformerNotFoundException(sprintf('Transformer "%s" not found.', $transformer)));
        }

        $imageTransformer = $this->transformerRegistry->get($transformer);
        if (!$imageTransformer instanceof LocalTransformerInterface) {
            throw $this->notFound($transformer, sprintf('Transformer "%s" does not support serving.', $transformer));
        }

        if (!$this->loaderRegistry->has($loader)) {
            throw $this->notFound($transformer, sprintf('Loader "%s" not found.', $loader), new LoaderNotFoundException(sprintf('Loader "%s" not found.', $loader)));
        }

        $imageLoader = $this->loaderRegistry->get($loader);
        if (!$imageLoader instanceof ServableLoaderInterface) {
            throw $this->notFound($transformer, sprintf('Loader "%s" does not support serving.', $loader));
        }

        $this->stopwatch?->start('picasso.image_response', 'picasso');

        try {
            $response = $imageTransformer->serve($imageLoader, $path, $request, [
                'transformer' => $transformer,
                'loader' => $loader,
            ]);
        } catch (ImageNotFoundException|UndecodableImageException $e) {
            throw $this->notFound($transformer, $e->getMessage(), $e);
        } finally {
            $this->stopwatch?->stop('picasso.image_response');
        }

        return $response;
    }

    /**
     * A 404 that a CDN may keep for the transformer's error_max_age, so repeated
     * requests for a missing image stop reaching the application. Without one, it
     * stays uncacheable.
     */
    private function notFound(string $transformer, string $message, ?Throwable $previous = null): NotFoundHttpException
    {
        $headers = isset($this->errorMaxAges[$transformer])
            ? ['Cache-Control' => sprintf('public, max-age=%d', $this->errorMaxAges[$transformer])]
            : [];

        return new NotFoundHttpException($message, $previous, 0, $headers);
    }
}
