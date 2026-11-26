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

use Silarhi\PicassoBundle\Exception\PicassoExceptionInterface;
use Silarhi\PicassoBundle\Service\ImagePipeline;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purges the cached variants of images, through the transformer that renders them.
 */
#[AsCommand(name: 'picasso:purge', description: 'Purge the cached variants of images')]
final class PurgeCommand extends Command
{
    public function __construct(
        private readonly ImagePipeline $pipeline,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Image paths, as given to the loader (read from the standard input, one per line, when omitted)')
            ->addOption('loader', 'l', InputOption::VALUE_REQUIRED, 'Loader of the images (default: default_loader)')
            ->addOption('transformer', 't', InputOption::VALUE_REQUIRED, 'Transformer whose cache is purged (default: the loader\'s, else default_transformer)')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command deletes every cached variant of the given images, so the next
                request renders them again (e.g. after replacing an original in place):

                  <info>php %command.full_name% products/shoe.jpg products/bag.jpg --loader=uploads</info>

                Paths are read from the standard input when no argument is given:

                  <info>find public/uploads -name '*.jpg' -printf '%P\n' | php %command.full_name% --loader=uploads</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $paths = PathsInput::read($input);
        if ([] === $paths) {
            $io->error('Give the paths of the images to purge, as arguments or on the standard input.');

            return Command::INVALID;
        }

        /** @var string|null $loader */
        $loader = $input->getOption('loader');
        /** @var string|null $transformer */
        $transformer = $input->getOption('transformer');

        $failures = 0;
        foreach ($paths as $path) {
            try {
                $this->pipeline->purge($path, $loader, $transformer);
                $io->writeln(sprintf('Purged <info>%s</info>', $path), OutputInterface::VERBOSITY_VERBOSE);
            } catch (PicassoExceptionInterface $e) {
                ++$failures;
                $io->writeln(sprintf('<error>%s</error>: %s', $path, $e->getMessage()));
            }
        }

        if (0 !== $failures) {
            $io->error(sprintf('%d of %d images could not be purged.', $failures, count($paths)));

            return Command::FAILURE;
        }

        $io->success(sprintf('Purged %d %s.', count($paths), 1 === count($paths) ? 'image' : 'images'));

        return Command::SUCCESS;
    }
}
