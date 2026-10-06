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

use Silarhi\PicassoBundle\Tests\Functional\Stub\PrivateDocumentController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import(\dirname(__DIR__, 2) . '/config/routes.php');

    $routes->add('portal_document_image', '/portal/{tenant}/documents/{id}/image')
        ->controller(PrivateDocumentController::class)
        ->methods(['GET']);
};
