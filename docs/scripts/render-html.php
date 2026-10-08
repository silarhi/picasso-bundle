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

use Silarhi\PicassoBundle\Tests\Functional\AbstractPicassoKernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

// Renders the Twig examples of the documentation with the bundle itself, so the HTML the pages show is the HTML
// PicassoBundle really outputs.
//
//     php docs/scripts/render-html.php
//
// Writes docs/src/data/html/<example>.html and stats.json.

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

final class DocsKernel extends AbstractPicassoKernel
{
    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('picasso', [
            'loaders' => ['filesystem' => ['path' => \dirname(__DIR__) . '/src/assets/source']],
            'transformers' => ['glide' => ['sign_key' => 'docs-example-key', 'cache' => sys_get_temp_dir() . '/picasso_docs/glide']],
            'placeholders' => ['blur' => ['type' => 'transformer']],
            'default_placeholder' => 'blur',
        ]);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/picasso_docs/cache';
    }
}

$examples = [
    'quick-start' => '<twig:Picasso:Image src="meeting.jpg" width="800" height="600" sizes="(max-width: 768px) 100vw, 800px" alt="A meeting" />',
    'fixed-size' => '<twig:Picasso:Image src="meeting.jpg" width="320" height="240" placeholder="{{ false }}" alt="A meeting" />',
    'priority' => '<twig:Picasso:Image src="meeting.jpg" width="1920" height="1080" sizes="100vw" priority="{{ true }}" alt="A meeting" />',
];

(new Filesystem())->remove(sys_get_temp_dir() . '/picasso_docs');
$kernel = new DocsKernel('prod', false);
$kernel->boot();
$twig = $kernel->getContainer()->get('test.service_container')->get('twig');

$output = \dirname(__DIR__) . '/src/data/html';
@mkdir($output, 0o755, true);

$stats = [];
foreach ($examples as $name => $template) {
    $html = $twig->createTemplate($template)->render();
    $stats[$name] = ['urls' => preg_match_all('#/image/glide/#', $html), 'widths' => preg_match_all('/ \d+w/', $html)];
    // Readable rather than exhaustive: URLs decoded, each srcset cut to its first two and last candidates
    $html = html_entity_decode($html);
    $html = preg_replace_callback('/srcset="([^"]+)"/', static function (array $m): string {
        $candidates = explode(', ', $m[1]);
        if (\count($candidates) <= 3) {
            return $m[0];
        }

        return \sprintf('srcset="%s, %s, … %d more …, %s"', $candidates[0], $candidates[1], \count($candidates) - 3, end($candidates));
    }, $html);
    // One attribute per line, like a formatter would: the generated tag is a single line otherwise
    $html = preg_replace('/\s+/', ' ', trim($html));
    $html = preg_replace('/\s(?=(srcset|sizes|src|width|loading|fetchpriority|style|onload|alt|type)=")/', "\n        ", $html);
    $html = str_replace(['<source', '<img', '</picture>', ' />'], ["\n    <source", "\n    <img", "\n</picture>", "\n    />"], $html);
    file_put_contents("{$output}/{$name}.html", ltrim($html) . "\n");
    echo "{$name}\n";
}

file_put_contents("{$output}/stats.json", json_encode($stats, \JSON_PRETTY_PRINT) . "\n");
