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

$ruleset = new TwigCsFixer\Ruleset\Ruleset();
$ruleset->addStandard(new TwigCsFixer\Standard\TwigCsFixer());

$config = new TwigCsFixer\Config\Config();
$config->setCacheFile(__DIR__ . '/var/tools/.twig-cs-fixer.cache');
$finder = new TwigCsFixer\File\Finder();
$finder->in([
    __DIR__ . '/templates',
]);
$config->setRuleset($ruleset);

return $config;
