<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('picasso_image', '/picasso/{transformer}/{loader}/{path}')
        ->controller('picasso.controller.image')
        ->requirements(['path' => '.+'])
        ->methods(['GET']);
};
