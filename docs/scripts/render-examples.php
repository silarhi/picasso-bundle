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

use Imagine\Gd\Imagine;
use League\Glide\ServerFactory;
use Silarhi\PicassoBundle\Dto\Image;
use Silarhi\PicassoBundle\Dto\ImageTransformation;
use Silarhi\PicassoBundle\Placeholder\BlurHashPlaceholder;

// Renders the example images of the documentation with the bundle's own transformations, so the pages show what
// Picasso really outputs: fit modes, placeholders and srcset variants of docs/src/assets/source/meeting.jpg.
//
//     php docs/scripts/render-examples.php
//
// Writes docs/src/assets/examples/ and docs/src/data/examples.json (byte sizes, dimensions, placeholder URIs).

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$docs = \dirname(__DIR__);
$sourceDir = $docs . '/src/assets/source';
$outputDir = $docs . '/src/assets/examples';
$cacheDir = sys_get_temp_dir() . '/picasso-docs-' . bin2hex(random_bytes(4));
$source = 'meeting.jpg';

$server = ServerFactory::create(['source' => $sourceDir, 'cache' => $cacheDir, 'driver' => 'gd']);

/**
 * Renders one variant through Glide, exactly as GlideTransformer asks for it, and copies it to the examples.
 *
 * @param array<string, int|string> $params
 *
 * @return array{file: string, bytes: int, width: int, height: int}
 */
$render = static function (string $name, array $params) use ($server, $source, $cacheDir, $outputDir): array {
    $cached = $server->makeImage($source, $params);
    $target = $outputDir . '/' . $name;
    copy($cacheDir . '/' . $cached, $target);
    [$width, $height] = getimagesize($target) ?: [0, 0];

    return ['file' => $name, 'bytes' => filesize($target) ?: 0, 'width' => $width, 'height' => $height];
};

$data = ['source' => ['width' => 0, 'height' => 0, 'bytes' => filesize($sourceDir . '/' . $source)]];
[$data['source']['width'], $data['source']['height']] = getimagesize($sourceDir . '/' . $source) ?: [0, 0];

// Fit modes: the same 480×480 box for each value of the `fit` prop
foreach (['contain', 'cover', 'crop', 'fill'] as $fit) {
    $data['fit'][$fit] = $render("fit-{$fit}.jpg", ['w' => 480, 'h' => 480, 'fit' => $fit, 'fm' => 'jpg', 'q' => 75]);
}

// Formats: one 800 wide variant per default format, to compare their weight
foreach (['avif', 'webp', 'jpg'] as $format) {
    $data['formats'][$format] = $render("format-800.{$format}", ['w' => 800, 'fm' => $format, 'q' => 75, 'fit' => 'contain']);
}

// Srcset: what the browser picks from on a phone, a laptop and a large screen
foreach ([640, 1080, 1920] as $width) {
    $data['srcset'][$width] = $render("srcset-{$width}.webp", ['w' => $width, 'fm' => 'webp', 'q' => 75, 'fit' => 'contain']);
}

// Display image of the placeholder demo
$data['full'] = $render('full-1200.jpg', ['w' => 1200, 'h' => 800, 'fit' => 'crop', 'fm' => 'jpg', 'q' => 75]);

// Transformer placeholder (LQIP): the defaults of `type: transformer`
$data['placeholders']['transformer'] = $render('placeholder-transformer.jpg', ['w' => 10, 'h' => 7, 'blur' => 5, 'q' => 30, 'fit' => 'crop', 'fm' => 'jpg']);
$data['placeholders']['transformer']['uri'] = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($outputDir . '/placeholder-transformer.jpg'));

// BlurHash placeholder: the bundle's own service, with its defaults
$blurhash = new BlurHashPlaceholder(new Imagine());
$uri = $blurhash->generate(
    new Image(path: $source, stream: static fn () => fopen($sourceDir . '/' . $source, 'r')),
    new ImageTransformation(width: 1200, height: 800),
);
$data['placeholders']['blurhash'] = ['uri' => $uri, 'bytes' => \strlen((string) base64_decode(explode(',', $uri, 2)[1]))];

file_put_contents($docs . '/src/data/examples.json', json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");

echo "Rendered into {$outputDir}\n";
