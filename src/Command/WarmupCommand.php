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

namespace Silarhi\PicassoBundle\Command;

use function count;
use function is_string;

use Silarhi\PicassoBundle\Controller\ImageController;
use Silarhi\PicassoBundle\Controller\LegacyUrlController;
use Silarhi\PicassoBundle\Dto\ImageRenderData;
use Silarhi\PicassoBundle\Exception\PicassoExceptionInterface;
use Silarhi\PicassoBundle\Service\ImageHelperInterface;
use Silarhi\PicassoBundle\Transformer\DeferredCacheWriter;

use function sprintf;
use function strlen;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Renders ahead of time the variants a page would request, so that no visitor waits for a cache miss.
 *
 * Every URL of the rendered <picture> (each srcset candidate of each format, the fallback, a transformer
 * placeholder) is served through the bundle controller, which stores the variants it renders. Variants rendered
 * elsewhere (Imgix) or by application routes are skipped.
 */
#[AsCommand(name: 'picasso:warmup', description: 'Render the cached variants of images ahead of time')]
final class WarmupCommand extends Command
{
    public function __construct(
        private readonly ImageHelperInterface $imageHelper,
        private readonly RouterInterface $router,
        private readonly ImageController|LegacyUrlController $controller,
        private readonly ?DeferredCacheWriter $deferredCacheWriter = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Image paths, as given to the loader (read from the standard input, one per line, when omitted)')
            ->addOption('preset', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Preset the images are rendered with; repeat it for several')
            ->addOption('loader', 'l', InputOption::VALUE_REQUIRED, 'Loader of the images (default: the preset\'s, else default_loader)')
            ->addOption('transformer', 't', InputOption::VALUE_REQUIRED, 'Transformer rendering the images (default: the preset\'s, else the loader\'s, else default_transformer)')
            ->addOption('width', null, InputOption::VALUE_REQUIRED, 'Display width, as given to the component')
            ->addOption('height', null, InputOption::VALUE_REQUIRED, 'Display height, as given to the component')
            ->addOption('sizes', null, InputOption::VALUE_REQUIRED, 'Responsive sizes attribute, as given to the component')
            ->addOption('fit', null, InputOption::VALUE_REQUIRED, 'Fit mode, as given to the component')
            ->addOption('quality', null, InputOption::VALUE_REQUIRED, 'Quality, as given to the component')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command renders the variants of images the way pages render them, so
                that their first visitors get them from the cache:

                  <info>php %command.full_name% products/shoe.jpg --loader=uploads --preset=card --preset=hero</info>

                Without a preset, give the options of the component (<comment>--width</comment>, <comment>--height</comment>, <comment>--sizes</comment>…). Paths are
                read from the standard input when no argument is given:

                  <info>find public/uploads -name '*.jpg' -printf '%P\n' | php %command.full_name% --loader=uploads --preset=card</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $paths = PathsInput::read($input);
        if ([] === $paths) {
            $io->error('Give the paths of the images to warm up, as arguments or on the standard input.');

            return Command::INVALID;
        }

        /** @var list<string> $presets */
        $presets = $input->getOption('preset');
        /** @var string|null $loader */
        $loader = $input->getOption('loader');
        /** @var string|null $transformer */
        $transformer = $input->getOption('transformer');
        /** @var string|null $sizes */
        $sizes = $input->getOption('sizes');
        /** @var string|null $fit */
        $fit = $input->getOption('fit');
        $width = $this->intOption($input, 'width');
        $height = $this->intOption($input, 'height');
        $quality = $this->intOption($input, 'quality');

        $served = 0;
        $skipped = 0;
        $failures = 0;
        $progress = $io->createProgressBar(count($paths));
        foreach ($paths as $path) {
            foreach ([] !== $presets ? $presets : [null] as $preset) {
                try {
                    $data = $this->imageHelper->imageData(
                        src: $path,
                        width: $width,
                        height: $height,
                        sizes: $sizes,
                        loader: $loader,
                        transformer: $transformer,
                        quality: $quality,
                        fit: $fit,
                        preset: $preset,
                    );
                } catch (PicassoExceptionInterface $e) {
                    ++$failures;
                    $io->writeln(sprintf("\n<error>%s</error>: %s", $path, $e->getMessage()));
                    continue;
                }

                foreach ($this->urls($data) as $url) {
                    $result = $this->serve($url);
                    if (true === $result) {
                        ++$served;
                    } elseif (null === $result) {
                        ++$skipped;
                    } else {
                        ++$failures;
                        $io->writeln(sprintf("\n<error>%s</error>: %s", $url, $result));
                    }
                }
            }

            // Uploads the variants rendered to local disk (defer_cache_write) before the next image
            $this->deferredCacheWriter?->flush();
            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        if (0 === $served && 0 !== $skipped) {
            $io->warning('No variant is rendered by the bundle: only local transformers (Glide) can be warmed up, Imgix renders its variants itself.');
        }

        if (0 !== $failures) {
            $io->error(sprintf('Served %d variants, %d failed.', $served, $failures));

            return Command::FAILURE;
        }

        $io->success(sprintf('Served %d variants of %d %s.', $served, count($paths), 1 === count($paths) ? 'image' : 'images'));

        return Command::SUCCESS;
    }

    /**
     * Every URL a browser may request for the image, once each.
     *
     * @return list<string>
     */
    private function urls(ImageRenderData $data): array
    {
        $srcsets = [$data->fallbackSrcset, ...array_map(static fn (\Silarhi\PicassoBundle\Dto\ImageSource $source): string => $source->srcset, $data->sources)];
        $urls = [$data->fallbackSrc];
        foreach ($srcsets as $srcset) {
            foreach (null !== $srcset && '' !== $srcset ? explode(', ', $srcset) : [] as $candidate) {
                $urls[] = explode(' ', $candidate, 2)[0];
            }
        }
        // A data URI placeholder (BlurHash) is not requested
        if (null !== $data->placeholderUri && !str_starts_with($data->placeholderUri, 'data:')) {
            $urls[] = $data->placeholderUri;
        }

        return array_values(array_unique(array_filter($urls, static fn (?string $url): bool => null !== $url && '' !== $url)));
    }

    /**
     * Serves a URL through the bundle controller, which renders and stores a missing variant.
     *
     * @return bool|string|null true when served, null when the bundle does not serve the URL, else the failure
     */
    private function serve(string $url): bool|string|null
    {
        // A base_url (CDN host) only prefixes the path the bundle route matches
        $path = parse_url($url, \PHP_URL_PATH);
        $query = parse_url($url, \PHP_URL_QUERY);
        if (!is_string($path)) {
            return null;
        }

        $baseUrl = $this->router->getContext()->getBaseUrl();
        if ('' !== $baseUrl && str_starts_with($path, $baseUrl)) {
            $path = substr($path, strlen($baseUrl));
        }

        try {
            $route = $this->router->match($path);
        } catch (RoutingException) {
            return null;
        }

        if ('picasso_image' !== ($route['_route'] ?? null) || !is_string($route['transformer'] ?? null) || !is_string($route['loader'] ?? null) || !is_string($route['path'] ?? null)) {
            return null;
        }

        $request = Request::create($path . (is_string($query) ? '?' . $query : ''));

        try {
            $response = ($this->controller)($route['transformer'], $route['loader'], $route['path'], $request);
        } catch (HttpExceptionInterface $e) {
            return sprintf('HTTP %d, %s', $e->getStatusCode(), $e->getPrevious()?->getMessage() ?? $e->getMessage());
        }

        return $response->isSuccessful() || $response->isRedirection() ? true : sprintf('HTTP %d', $response->getStatusCode());
    }

    private function intOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);

        return is_string($value) && '' !== $value ? (int) $value : null;
    }
}
