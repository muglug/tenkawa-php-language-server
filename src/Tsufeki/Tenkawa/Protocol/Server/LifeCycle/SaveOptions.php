<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Protocol\Server\LifeCycle;

class SaveOptions
{
    /**
     * The client is supposed to include the content on save.
     *
     * @var bool
     */
    public $includeText = false;
}
