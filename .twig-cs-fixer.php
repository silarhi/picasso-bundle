<?php

declare(strict_types=1);

$config = new TwigCsFixer\Config\Config();
$config->setFinder(
    TwigCsFixer\File\Finder::create()
        ->in(__DIR__.'/templates')
);

return $config;
