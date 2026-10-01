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

/*
 * Stress benchmarks: what crawlers cost a site serving its images through the bundle.
 *
 *   XDEBUG_MODE=off php -d opcache.enable_cli=1 benchmarks/run.php [scenario ...]
 *
 * Scenarios: render, hit, notfound, miss, crawl, herd, memory (default: all).
 * Every scenario prints a Markdown table. Source images (small, HD, 4K) are
 * generated on the first run under benchmarks/var/, which can be deleted at will.
 */

namespace Silarhi\PicassoBundle\Benchmarks;

use function array_slice;
use function assert;
use function count;
use function dirname;
use function extension_loaded;
use function function_exists;
use function ini_get;
use function is_array;
use function is_float;
use function is_int;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;

use function sprintf;
use function strlen;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/BenchKernel.php';
require_once __DIR__ . '/CountingStorage.php';

const IMAGES = [
    'small' => [800, 600],
    'hd' => [1920, 1080],
    '4k' => [3840, 2160],
];
const FORMATS = ['jpg', 'webp', 'avif'];

// ------------------------------------------------------------------ helpers

/**
 * @param array<string, mixed> $options
 */
function kernel(array $options = []): BenchKernel
{
    $kernel = new BenchKernel($options);
    $kernel->boot();

    return $kernel;
}

function helper(BenchKernel $kernel): ImageHelperInterface
{
    $helper = $kernel->getContainer()->get('test.service_container')->get('picasso.image_helper');
    assert($helper instanceof ImageHelperInterface);

    return $helper;
}

/**
 * One request the way a worker handles it: same kernel, body sent, terminate.
 *
 * @param array<string, string> $headers
 */
function handle(BenchKernel $kernel, string $uri, array $headers = []): Response
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create($uri, server: $server);
    $response = $kernel->handle($request);
    ob_start();
    $response->sendContent();
    ob_end_clean();
    $kernel->terminate($request, $response);

    return $response;
}

/**
 * @return float microseconds per call
 */
function measure(callable $fn, int $iterations): float
{
    $fn(0);
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $fn($i);
    }

    return (hrtime(true) - $start) / 1e3 / $iterations;
}

function peakRssMb(): float
{
    $maxRss = getrusage()['ru_maxrss'];

    // bytes on macOS, kilobytes on Linux
    return 'Darwin' === \PHP_OS_FAMILY ? $maxRss / 1048576 : $maxRss / 1024;
}

function currentRssMb(): float
{
    // Linux, including BusyBox (Alpine), whose ps has no "-o rss= -p"
    $status = @file_get_contents('/proc/self/status');
    if (false !== $status && 1 === preg_match('/^VmRSS:\s+(\d+) kB/m', $status, $matches)) {
        return (int) $matches[1] / 1024;
    }

    return (int) shell_exec('ps -o rss= -p ' . getmypid()) / 1024;
}

function cpuSeconds(): float
{
    $usage = getrusage();

    return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
}

function directorySize(string $directory): int
{
    $size = 0;
    if (is_dir($directory)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
    }

    return $size;
}

function clearCache(BenchKernel $kernel): void
{
    (new Filesystem())->remove([$kernel->glideCacheDir(), $kernel->writeLog()]);
    mkdir($kernel->glideCacheDir(), 0o777, true);
}

/**
 * Runs a scenario step in a fresh PHP process, for per-case peak memory and real concurrency.
 *
 * @param array<string, mixed> $args
 *
 * @return resource
 */
function spawn(string $step, array $args, mixed &$pipes)
{
    $command = [\PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'memory_limit=-1', __FILE__, '_child', $step, (string) json_encode($args)];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['XDEBUG_MODE' => 'off', ...getenv()]);
    if (false === $process) {
        throw new RuntimeException('Cannot start a benchmark process.');
    }

    return $process;
}

/**
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>
 */
function child(string $step, array $args): array
{
    $process = spawn($step, $args, $pipes);
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    proc_close($process);

    // The result is the last line: anything before it is noise (logs, warnings)
    $lines = explode("\n", trim($output));
    $result = json_decode((string) end($lines), true);
    if (!is_array($result)) {
        throw new RuntimeException("Benchmark step \"$step\" failed:\n$output\n$errors");
    }

    return array_filter($result, is_string(...), \ARRAY_FILTER_USE_KEY);
}

/**
 * @param list<string>       $headers
 * @param list<list<string>> $rows
 */
function table(string $title, array $headers, array $rows): void
{
    echo "\n### $title\n\n";
    echo '| ' . implode(' | ', $headers) . " |\n";
    echo '|' . implode('|', array_map(static fn (string $h): string => str_repeat('-', max(3, strlen($h) + 2)), $headers)) . "|\n";
    foreach ($rows as $row) {
        echo '| ' . implode(' | ', $row) . " |\n";
    }
}

/**
 * @return list<string>
 */
function availableDrivers(): array
{
    $drivers = ['gd'];
    if (extension_loaded('imagick')) {
        $drivers[] = 'imagick';
    }
    if (class_exists('Intervention\\Image\\Drivers\\Vips\\Driver') && extension_loaded('ffi')) {
        try {
            kernel(['driver' => 'vips']);
            $drivers[] = 'vips';
        } catch (Throwable) {
            // libvips missing
        }
    }

    return $drivers;
}

/**
 * Every URL of the default responsive markup of an image (sizes="100vw").
 *
 * @return list<string>
 */
function srcsetUrls(ImageHelperInterface $helper, string $image): array
{
    $data = $helper->imageData(src: $image . '.jpg', sizes: '100vw', sourceWidth: IMAGES[$image][0], sourceHeight: IMAGES[$image][1]);
    $urls = [];
    foreach ([...array_map(static fn ($source): string => $source->srcset, $data->sources), (string) $data->fallbackSrcset] as $srcset) {
        foreach (explode(', ', $srcset) as $entry) {
            $urls[] = explode(' ', $entry)[0];
        }
    }

    return $urls;
}

function generateImages(): void
{
    @mkdir(BenchKernel::imagesDir(), 0o777, true);
    foreach (IMAGES as $name => [$width, $height]) {
        $file = BenchKernel::imagesDir() . "/$name.jpg";
        if (is_file($file)) {
            continue;
        }

        // Photo-like: gradients and per-pixel noise, so encoders cannot cheat on flat colours
        $image = imagecreatetruecolor($width, $height);
        mt_srand(42);
        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                $noise = mt_rand(-18, 18);
                $color = (int) imagecolorallocate(
                    $image,
                    max(0, min(255, (int) (255 * $x / $width) + $noise)),
                    max(0, min(255, (int) (255 * $y / $height) + $noise)),
                    max(0, min(255, (int) (128 + 127 * sin(($x + $y) / 90)) + $noise)),
                );
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, $color);
            }
        }
        imagejpeg($image, $file, 90);
    }
    for ($i = 0; $i < 30; ++$i) {
        @copy(BenchKernel::imagesDir() . '/hd.jpg', BenchKernel::imagesDir() . "/gallery-$i.jpg");
    }
}

// ------------------------------------------------------------------ scenarios

function render(): void
{
    $kernel = kernel();
    $helper = helper($kernel);
    $twig = $kernel->getContainer()->get('test.service_container')->get('twig');
    assert($twig instanceof Environment);
    $page = $twig->createTemplate("{% for i in 0..29 %}{{ picasso_image('gallery-' ~ i ~ '.jpg', sizes='(max-width: 768px) 100vw, 33vw', attributes={alt: ''}) }}\n{% endfor %}");
    $urls = count(srcsetUrls($helper, 'hd'));

    table('Rendering (HTML pages)', ['Operation', 'Time'], [
        ['One URL (`picasso_image_url()`)', sprintf('%.1f µs', measure(static fn (int $i) => $helper->imageUrl('gallery-' . ($i % 30) . '.jpg', width: 640, format: 'webp'), 20000))],
        ['Image with fixed width and height (7 URLs)', sprintf('%.1f µs', measure(static fn (int $i) => $helper->imageData(src: 'gallery-' . ($i % 30) . '.jpg', width: 800, height: 600), 5000))],
        ["Responsive image, `sizes` set ($urls URLs)", sprintf('%.1f µs', measure(static fn (int $i) => $helper->imageData(src: 'gallery-' . ($i % 30) . '.jpg', sizes: '100vw'), 2000))],
        ['Responsive image + `resolveMetadata` (PSR-6 hit)', sprintf('%.1f µs', measure(static fn (int $i) => $helper->imageData(src: 'gallery-' . ($i % 30) . '.jpg', sizes: '100vw', resolveMetadata: true), 2000))],
        ['Twig page with 30 responsive images', sprintf('%.2f ms', measure(static fn () => $page->render([]), 200) / 1000)],
    ]);
}

function hit(): void
{
    $rows = [];
    foreach ([
        'Glide cache' => [],
        'public cache' => ['public' => true],
        'deferred writes' => ['defer' => true],
    ] as $mode => $options) {
        foreach ([0 => 'local disk', 10000 => 'object store (10 ms/call)'] as $latency => $storage) {
            $kernel = kernel(['storage' => 'counting', 'latency_us' => $latency, ...$options]);
            clearCache($kernel);
            $url = helper($kernel)->imageUrl('hd.jpg', width: 640, format: 'webp');
            $lastModified = (string) handle($kernel, $url)->headers->get('Last-Modified');
            $iterations = $latency > 0 ? 20 : 3000;

            CountingStorage::$calls = [];
            $hit = measure(static fn () => handle($kernel, $url), $iterations);
            $hitCalls = array_sum(CountingStorage::$calls) / ($iterations + 1);

            CountingStorage::$calls = [];
            $notModified = '' === $lastModified ? null : measure(static fn () => handle($kernel, $url, ['If-Modified-Since' => $lastModified]), $iterations);
            $notModifiedCalls = array_sum(CountingStorage::$calls) / ($iterations + 1);

            $rows[] = [
                $mode,
                $storage,
                sprintf('%d', $hitCalls),
                $latency > 0 ? sprintf('%.1f ms', $hit / 1000) : sprintf('%.0f µs (%s req/s)', $hit, number_format(1e6 / $hit)),
                null === $notModified ? 'no Last-Modified' : sprintf('%d call, %s', $notModifiedCalls, $latency > 0 ? sprintf('%.1f ms', $notModified / 1000) : sprintf('%.0f µs', $notModified)),
            ];
        }
    }

    table('Cache hits (one PHP worker, one core)', ['Mode', 'Cache storage', 'Storage calls', '200', '304'], $rows);
}

function notFound(): void
{
    $kernel = kernel();
    $helper = helper($kernel);
    $missing = $helper->imageUrl('missing.jpg', width: 640);
    $valid = $helper->imageUrl('hd.jpg', width: 640, format: 'webp');
    $rows = [];
    foreach ([
        'Invalid signature' => static fn (int $i) => handle($kernel, '/image/glide/main/hd.jpg?w=' . ($i + 1) . '&s=forged'),
        'Valid signature, missing image' => static fn () => handle($kernel, $missing),
        'Unknown loader' => static fn () => handle($kernel, '/image/glide/nope/hd.jpg'),
        'Tracking param appended (`&utm_source=x`)' => static fn () => handle($kernel, $valid . '&utm_source=x'),
    ] as $label => $fn) {
        $rows[] = [$label, sprintf('%.0f µs', measure($fn, 2000))];
    }

    table('Junk URLs (all answered 404)', ['Request', 'Time'], $rows);
}

function miss(): void
{
    $drivers = availableDrivers();
    $rows = [];
    foreach (IMAGES as $image => [$width]) {
        foreach (array_unique([640, $width]) as $targetWidth) {
            foreach (FORMATS as $format) {
                $row = [$image, (string) $targetWidth, $format];
                foreach ($drivers as $driver) {
                    $result = child('miss', ['driver' => $driver, 'image' => $image, 'width' => $targetWidth, 'format' => $format]);
                    $row[] = sprintf('%.0f ms, %.0f MB', $result['ms'], $result['rss']);
                }
                $rows[] = $row;
            }
        }
    }

    table('Cache misses: render time and peak memory (RSS) of one variant', ['Source', 'Width', 'Format', ...$drivers], $rows);
}

function crawl(): void
{
    $drivers = availableDrivers();
    $rows = [];
    foreach (array_keys(IMAGES) as $image) {
        $row = [$image];
        foreach ($drivers as $driver) {
            $result = child('crawl', ['driver' => $driver, 'image' => $image]);
            $row[] = sprintf('%d variants, %.1f s, %.1f MB', $result['variants'], $result['seconds'], $result['cacheMb']);
        }
        $rows[] = $row;
    }

    table('A crawler fetching every srcset URL of one cold image', ['Source', ...$drivers], $rows);
}

function herd(): void
{
    $concurrency = 16;
    $rows = [];
    foreach ([false, true] as $lock) {
        $options = ['storage' => 'counting', 'lock' => $lock];
        $kernel = kernel($options);
        clearCache($kernel);
        $url = helper($kernel)->imageUrl('4k.jpg', width: 1920, format: 'webp');

        $startAt = microtime(true) + 1.5;
        $processes = [];
        for ($i = 0; $i < $concurrency; ++$i) {
            $processes[] = [spawn('request', ['options' => $options, 'url' => $url, 'startAt' => $startAt], $pipes), $pipes];
        }
        $cpu = 0.0;
        $statuses = [];
        foreach ($processes as [$process, $pipes]) {
            $result = json_decode((string) stream_get_contents($pipes[1]), true);
            proc_close($process);
            assert(is_array($result));
            $cpu += $result['cpu'];
            $statuses[] = $result['status'];
        }
        $wall = microtime(true) - $startAt;

        $rows[] = [
            $lock ? 'on' : 'off',
            (string) count(file($kernel->writeLog()) ?: []),
            implode(', ', array_map(static fn ($status, $count) => "$count × $status", array_keys(array_count_values($statuses)), array_count_values($statuses))),
            sprintf('%.1f s', $cpu),
            sprintf('%.2f s', $wall),
        ];
    }

    table("$concurrency concurrent requests for the same cold variant (4K source, webp, 1920 wide, gd)", ['lock', 'Renders', 'Responses', 'CPU (all processes)', 'Wall time'], $rows);
}

function memory(): void
{
    $rows = [];
    foreach (availableDrivers() as $driver) {
        foreach (FORMATS as $format) {
            $result = child('memory', ['driver' => $driver, 'format' => $format, 'renders' => 60]);
            $rows[] = [$driver, $format, sprintf('%.0f MB', $result['start']), sprintf('%.0f MB', $result['end']), sprintf('%+.2f MB', $result['perRender'])];
        }
    }

    table('Long-running worker: RSS over 60 cold renders (HD source, 3 widths)', ['Driver', 'Format', 'RSS after 15 renders', 'RSS after 60', 'Growth per render'], $rows);
}

// ------------------------------------------------------------------ child steps

/**
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>
 */
function childStep(string $step, array $args): array
{
    switch ($step) {
        case 'miss':
            $kernel = kernel(['driver' => $args['driver']]);
            assert(\is_string($args['image']) && is_int($args['width']) && \is_string($args['format']));
            $url = helper($kernel)->imageUrl($args['image'] . '.jpg', width: $args['width'], format: $args['format']);
            $times = [];
            for ($i = 0; $i < 3; ++$i) {
                clearCache($kernel);
                $start = hrtime(true);
                $response = handle($kernel, $url);
                $times[] = (hrtime(true) - $start) / 1e6;
                if (200 !== $response->getStatusCode()) {
                    throw new RuntimeException("$url answered {$response->getStatusCode()}");
                }
            }
            sort($times);

            return ['ms' => $times[1], 'rss' => peakRssMb()];

        case 'crawl':
            $kernel = kernel(['driver' => $args['driver']]);
            clearCache($kernel);
            assert(\is_string($args['image']));
            $urls = srcsetUrls(helper($kernel), $args['image']);
            $start = hrtime(true);
            foreach ($urls as $url) {
                handle($kernel, $url);
            }

            return ['variants' => count($urls), 'seconds' => (hrtime(true) - $start) / 1e9, 'cacheMb' => directorySize($kernel->glideCacheDir()) / 1048576];

        case 'request':
            assert(is_array($args['options']) && \is_string($args['url']) && is_float($args['startAt']));
            $kernel = kernel($args['options']);
            $cpu = cpuSeconds();
            usleep(max(0, (int) (($args['startAt'] - microtime(true)) * 1e6)));
            $response = handle($kernel, $args['url']);

            return ['status' => $response->getStatusCode(), 'cpu' => cpuSeconds() - $cpu];

        case 'memory':
            $kernel = kernel(['driver' => $args['driver']]);
            $helper = helper($kernel);
            assert(\is_string($args['format']) && is_int($args['renders']));
            $urls = array_map(static fn (int $width): string => $helper->imageUrl('hd.jpg', width: $width, format: (string) $args['format']), [640, 1080, 1920]);
            $start = 0.0;
            for ($i = 1; $i <= $args['renders']; ++$i) {
                if (0 === ($i - 1) % 3) {
                    clearCache($kernel);
                }
                handle($kernel, $urls[($i - 1) % 3]);
                if (15 === $i) {
                    $start = currentRssMb();
                }
            }
            $end = currentRssMb();

            return ['start' => $start, 'end' => $end, 'perRender' => ($end - $start) / ($args['renders'] - 15)];
    }

    throw new RuntimeException("Unknown step \"$step\".");
}

// ------------------------------------------------------------------ main

if ('_child' === ($argv[1] ?? null)) {
    /** @var array<string, mixed> $args */
    $args = json_decode($argv[3] ?? '{}', true);
    echo "\n", json_encode(childStep($argv[2] ?? '', $args)), "\n";

    exit(0);
}

if (function_exists('xdebug_info') && 'off' !== (getenv('XDEBUG_MODE') ?: ini_get('xdebug.mode'))) {
    fwrite(\STDERR, "Xdebug is active: run with XDEBUG_MODE=off for meaningful numbers.\n");
}
if (!filter_var(ini_get('opcache.enable_cli'), \FILTER_VALIDATE_BOOL)) {
    fwrite(\STDERR, "OPcache is off for the CLI: run with -d opcache.enable_cli=1 for meaningful numbers.\n");
}

$scenarios = ['render' => render(...), 'hit' => hit(...), 'notfound' => notFound(...), 'miss' => miss(...), 'crawl' => crawl(...), 'herd' => herd(...), 'memory' => memory(...)];
$selected = array_slice($argv, 1) ?: array_keys($scenarios);

generateImages();
printf("PHP %s, %s, GD %s, %s, league/glide %s\n", \PHP_VERSION, \PHP_OS_FAMILY, gd_info()['GD Version'], implode(', ', availableDrivers()), \Composer\InstalledVersions::getPrettyVersion('league/glide'));

foreach ($selected as $name) {
    if (!isset($scenarios[$name])) {
        fwrite(\STDERR, sprintf("Unknown scenario \"%s\". Available: %s\n", $name, implode(', ', array_keys($scenarios))));

        exit(1);
    }
    $scenarios[$name]();
}
