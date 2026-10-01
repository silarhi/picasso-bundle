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

use function assert;

use Psr\Container\ContainerInterface;
use Silarhi\PicassoBundle\Exception\ImageNotFoundException;
use Silarhi\PicassoBundle\Service\LegacyMetadataResolver;
use Silarhi\PicassoBundle\Service\UrlAliases;
use Silarhi\PicassoBundle\Transformer\GlideTransformer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects 1.x Glide URLs to their 2.0 URL, and hands every other request to the
 * image controller.
 *
 * In 1.x, a loader reading several roots put the root of each image in an
 * encrypted "_metadata" param. Since 2.0 a loader reads a single root, so that
 * root names the loader serving the image, whatever the loader in the URL is
 * now (renamed, a chain, another root). Such URLs are answered with a 301 to the
 * same image and transformation under that loader. Decorates the image
 * controller whenever a Glide transformer is configured, since only Glide
 * minted them.
 *
 * @internal Removed with 1.x URL support
 */
final readonly class LegacyUrlController
{
    private const METADATA_PARAM = '_metadata';

    /**
     * @param ContainerInterface $glideTransformers The Glide transformers, by name
     * @param UrlAliases         $urlAliases        Turns the transformer URL segment back into a name
     * @param int|null           $errorMaxAge       Seconds a 404 may be cached (cache_control.error_max_age)
     */
    public function __construct(
        private ImageController $imageController,
        private ContainerInterface $glideTransformers,
        private LegacyMetadataResolver $resolver,
        private UrlAliases $urlAliases,
        private ?int $errorMaxAge = null,
    ) {
    }

    public function __invoke(string $transformer, string $loader, string $path, Request $request): Response
    {
        $token = $request->query->getString(self::METADATA_PARAM);
        $transformerName = $this->urlAliases->resolveTransformer($transformer);

        if ('' === $token || !$this->glideTransformers->has($transformerName)) {
            return ($this->imageController)($transformer, $loader, $path, $request);
        }

        $glide = $this->glideTransformers->get($transformerName);
        assert($glide instanceof GlideTransformer);

        try {
            $target = $this->resolver->resolveLoader($token);
            $response = $glide->redirectToLoader($target, $path, $request, ['transformer' => $transformerName, 'loader' => $loader]);
        } catch (ImageNotFoundException $e) {
            throw (new NotFoundExceptionFactory($this->errorMaxAge))->create($e->getMessage(), $e);
        }

        trigger_deprecation('silarhi/picasso-bundle', '2.0', 'A 1.x image URL of loader "%s" was redirected to loader "%s". Support for 1.x URLs will be removed in 3.0.', $loader, $target);

        return $response;
    }
}
