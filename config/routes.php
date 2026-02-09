<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('picasso_image', '/picasso/image/{path}')
        ->controller('picasso.controller.image::serve')
        ->requirements(['path' => '.+'])
        ->methods(['GET']);
};
