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

/**
 * Serves images through local transformers (e.g. Glide) and owns the HTTP cache
 * headers of what it serves: transformers render, the controller decides how
 * long clients and CDNs may keep the result.
 */
final readonly class ImageController
{
    /**
     * @param CacheControlConfig $cacheControl the bundle's cache_control config: max_age (seconds a served image may be
     *                                         cached; null keeps the transformer's headers), immutable, and
     *                                         error_max_age (seconds a 404 may be cached; null keeps it uncacheable)
     */
    public function __construct(
        private TransformerRegistry $transformerRegistry,
        private LoaderRegistry $loaderRegistry,
        private array $cacheControl,
        private ?Stopwatch $stopwatch = null,
    ) {
    }

    public function __invoke(string $transformer, string $loader, string $path, Request $request): Response
    {
        if (!$this->transformerRegistry->has($transformer)) {
            throw $this->notFound(sprintf('Transformer "%s" not found.', $transformer), new TransformerNotFoundException(sprintf('Transformer "%s" not found.', $transformer)));
        }

        $imageTransformer = $this->transformerRegistry->get($transformer);
        if (!$imageTransformer instanceof LocalTransformerInterface) {
            throw $this->notFound(sprintf('Transformer "%s" does not support serving.', $transformer));
        }

        if (!$this->loaderRegistry->has($loader)) {
            throw $this->notFound(sprintf('Loader "%s" not found.', $loader), new LoaderNotFoundException(sprintf('Loader "%s" not found.', $loader)));
        }

        $imageLoader = $this->loaderRegistry->get($loader);
        if (!$imageLoader instanceof ServableLoaderInterface) {
            throw $this->notFound(sprintf('Loader "%s" does not support serving.', $loader));
        }

        $this->stopwatch?->start('picasso.image_response', 'picasso');

        try {
            $response = $imageTransformer->serve($imageLoader, $path, $request, [
                'transformer' => $transformer,
                'loader' => $loader,
            ]);
        } catch (ImageNotFoundException|UndecodableImageException $e) {
            throw $this->notFound($e->getMessage(), $e);
        } finally {
            $this->stopwatch?->stop('picasso.image_response');
        }

        return $this->applyCacheHeaders($response);
    }

    /**
     * Served images (and their 304s) get the configured lifetime. Redirects and
     * other responses keep whatever the transformer set.
     */
    private function applyCacheHeaders(Response $response): Response
    {
        $maxAge = $this->cacheControl['max_age'];
        if (null === $maxAge || (!$response->isSuccessful() && Response::HTTP_NOT_MODIFIED !== $response->getStatusCode())) {
            return $response;
        }

        $response->setPublic();
        $response->setMaxAge($maxAge);
        $response->setImmutable($this->cacheControl['immutable']);
        // max-age is the lifetime; a transformer's Expires would only contradict it
        $response->headers->remove('Expires');

        return $response;
    }

    private function notFound(string $message, ?Throwable $previous = null): NotFoundHttpException
    {
        return (new NotFoundExceptionFactory($this->cacheControl['error_max_age']))->create($message, $previous);
    }
}
