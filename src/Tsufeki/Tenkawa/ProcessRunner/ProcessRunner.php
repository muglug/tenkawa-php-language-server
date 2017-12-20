<?php

namespace Tsufeki\Tenkawa\ProcessRunner;

interface ProcessRunner
{
    /**
     * @param string[] $cmd
     * @param string|null $stdin
     *
     * @resolve ProcessResult
     */
    public function run(array $cmd, string $stdin = null): \Generator;
}
