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

namespace Silarhi\PicassoBundle\Tests\Source\Stub;

use Psr\Http\Message\ResponseInterface;

/**
 * Builds the exceptions object store clients throw, for TestCase classes.
 */
trait ObjectStoreFailures
{
    /**
     * @param int|null $status The status the storage answered; null when it did not answer
     */
    private static function objectStoreException(?int $status): ObjectStoreException
    {
        if (null === $status) {
            return new ObjectStoreException(null);
        }

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);

        return new ObjectStoreException($response);
    }
}
