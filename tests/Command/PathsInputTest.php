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

namespace Silarhi\PicassoBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Silarhi\PicassoBundle\Command\PathsInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;

class PathsInputTest extends TestCase
{
    public function testArgumentsWinOverTheInputStream(): void
    {
        $input = $this->input(['paths' => ['a.jpg', 'b.jpg']]);
        $input->setStream($this->stream("c.jpg\n"));

        self::assertSame(['a.jpg', 'b.jpg'], PathsInput::read($input));
    }

    public function testReadsOnePathPerLineOfTheInputStream(): void
    {
        $input = $this->input();
        $input->setStream($this->stream("a.jpg\n\n  b.jpg  \r\nc.jpg"));

        self::assertSame(['a.jpg', 'b.jpg', 'c.jpg'], PathsInput::read($input), 'blank lines are skipped and lines trimmed');
    }

    public function testReadsTheStandardInputWhenTheInputHasNoStream(): void
    {
        self::assertSame(['a.jpg', 'b.jpg'], PathsInput::read($this->input(), $this->stream("a.jpg\nb.jpg\n")));
    }

    public function testNoPathsWithoutReadableStandardInput(): void
    {
        $stdin = $this->stream('a.jpg');
        fclose($stdin);

        self::assertSame([], PathsInput::read($this->input(), $stdin));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function input(array $parameters = []): ArrayInput
    {
        return new ArrayInput($parameters, new InputDefinition([new InputArgument('paths', InputArgument::IS_ARRAY)]));
    }

    /**
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
