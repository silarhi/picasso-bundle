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

use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import(\dirname(__DIR__, 2) . '/config/routes.php');

    // An application page whose path is the one of the Imgix URLs of photo.jpg
    $routes->add('photo_page', '/photo.jpg')
        ->controller([RedirectController::class, 'urlRedirectAction'])
        ->defaults(['path' => '/', 'permanent' => false]);
};
