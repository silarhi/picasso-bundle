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

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('picasso_image', '/image/{transformer}/{loader}/{path}')
        ->controller('picasso.controller.image')
        ->requirements(['path' => '.+'])
        ->methods(['GET']);

    $routes->add('picasso_image_cached', '/cache/picasso/{transformer}/{loader}/{path}')
        ->controller('picasso.controller.image::cached')
        ->requirements(['path' => '.+'])
        ->methods(['GET']);
};
