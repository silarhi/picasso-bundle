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

use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveReturnTagIncompatibleWithNativeTypeRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;

return RectorConfig::configure()
    ->withPaths([
        'src',
        'tests',
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        // Would name the vich mapping class installed locally: PropertyMapping (vich 2) or PropertyMappingInterface
        // (vich 3), and naming either breaks the other major. VichMappingHelper keeps mappings in local variables on purpose.
        ExplicitNullableObjectInstanceRector::class => [__DIR__ . '/src/Loader/VichMappingHelper.php'],
        // A stream wrapper method keeps the signature PHP calls it with
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__ . '/tests/Source/Stub/UnreadableFileStreamWrapper.php'],
        // Would turn the Twig path into "src/../templates", which then shows in Twig cache keys, debug:twig and errors
        DirnameDirConcatStringToDirectStringPathRector::class => [__DIR__ . '/src/PicassoBundle.php'],
        // Would drop @return TransformerParams / CacheControlConfig: Rector does not resolve PHPStan type aliases
        RemoveReturnTagIncompatibleWithNativeTypeRector::class => [
            __DIR__ . '/src/Transformer/GlideTransformer.php',
            __DIR__ . '/src/Transformer/ImgixTransformer.php',
            __DIR__ . '/tests/Controller/ImageControllerTest.php',
        ],
    ])
    ->reportUnusedSkips();
