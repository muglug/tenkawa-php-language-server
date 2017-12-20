<?php

namespace Tsufeki\Tenkawa\ProcessRunner;

use React\ChildProcess\Process;
use function React\Promise\Stream\buffer;
use Recoil\Recoil;
use Recoil\Listener;
use Tsufeki\Tenkawa\Utils\Event;

class ReactProcessRunner implements ProcessRunner
{
    public function run(array $cmd, string $stdin = null): \Generator
    {
        $cmdString = $this->buildCommand($cmd);
        $loop = yield Recoil::eventLoop();

        $process = new Process($cmdString);
        $process->start($loop, 0.05);

        $result = new ProcessResult();

        if ($stdin !== null) {
            $process->stdin->write($stdin);
        }
        $process->stdin->end();

        list(
            list($result->exitCode, $result->signal),
            $result->stdout,
            $result->stderr,
        ) = yield [
            Event::first($process, 'exit'),
            buffer($process->stdout),
            buffer($process->stderr),
        ];

        return $result;
    }

    /**
     * @param string[] $cmd
     */
    private function buildCommand(array $cmd): string
    {
        return implode(' ', array_map('escapeshellarg', $cmd));
    }
}
