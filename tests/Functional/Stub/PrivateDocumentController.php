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

namespace Silarhi\PicassoBundle\Tests\Functional\Stub;

use Silarhi\PicassoBundle\Dto\ImageReference;
use Silarhi\PicassoBundle\Service\ImageServer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An application route serving private images: it decides access itself (here
 * from a header, standing for a security voter), then lets ImageServer render.
 */
final readonly class PrivateDocumentController
{
    /** Document id → image path, standing for an entity holding its upload. */
    private const DOCUMENTS = ['42' => 'photo.jpg', '7' => 'test.txt'];

    public function __construct(
        private ImageServer $images,
    ) {
    }

    public function __invoke(string $tenant, string $id, Request $request): Response
    {
        if ('granted' !== $request->headers->get('X-Access')) {
            throw new AccessDeniedHttpException();
        }

        $path = self::DOCUMENTS[$id] ?? throw new NotFoundHttpException();

        return $this->images->serve($request, new ImageReference($path), 'documents');
    }
}
