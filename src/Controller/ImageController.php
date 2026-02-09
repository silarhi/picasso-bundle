<?php

namespace Silarhi\PicassoBundle\Controller;

use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Responses\SymfonyResponseFactory;
use League\Glide\Server;
use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageController
{
    public function __construct(
        private readonly Server $glideServer,
        private readonly string $signKey,
    ) {
    }

    public function serve(string $path, Request $request): Response
    {
        $params = $request->query->all();

        try {
            SignatureFactory::create($this->signKey)
                ->validateRequest($path, $params);
        } catch (SignatureException $e) {
            throw new NotFoundHttpException('Invalid image signature.', $e);
        }

        $this->glideServer->setResponseFactory(new SymfonyResponseFactory($request));

        try {
            return $this->glideServer->getImageResponse($path, $params);
        } catch (FileNotFoundException|\InvalidArgumentException $e) {
            throw new NotFoundHttpException('Image not found.', $e);
        }
    }
}
