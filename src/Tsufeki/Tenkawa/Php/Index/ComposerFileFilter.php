<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Php\Index;

use Tsufeki\Tenkawa\Server\Io\FileLister\FileFilter;
use Webmozart\Glob\Glob;

class ComposerFileFilter implements FileFilter
{
    /**
     * @var string[]
     */
    private $rejectGlobs;

    /**
     * @var string[]
     */
    private $acceptGlobs;

    /**
     * @var string[]
     */
    private $forceRejectGlobs;

    /**
     * @param string[] $rejectGlobs
     * @param string[] $acceptGlobs
     * @param string[] $forceRejectGlobs
     */
    public function __construct(array $rejectGlobs, array $acceptGlobs, array $forceRejectGlobs)
    {
        $this->rejectGlobs = array_unique($rejectGlobs);
        $this->acceptGlobs = array_unique($acceptGlobs);
        $this->forceRejectGlobs = array_unique($forceRejectGlobs);
    }

    public function filter(string $uri, string $baseUri): int
    {
        $accept = !$this->matchArray($this->rejectGlobs, $uri)
            || ($this->matchArray($this->acceptGlobs, $uri)
            && !$this->matchArray($this->forceRejectGlobs, $uri));

        return $accept ? self::ABSTAIN : self::REJECT;
    }

    public function getFileType(): string
    {
        return '';
    }

    public function enterDirectory(string $uri, string $baseUri): int
    {
        return self::ABSTAIN;
    }

    private function matchArray(array $globs, string $uri): bool
    {
        foreach ($globs as $glob) {
            if (Glob::match($uri, $glob)) { // TODO: windows support
                return true;
            }
        }

        return false;
    }
}