<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Server\Feature\GoToImplementation;

use Tsufeki\Tenkawa\Server\Document\Document;
use Tsufeki\Tenkawa\Server\Feature\Common\LocationLink;
use Tsufeki\Tenkawa\Server\Feature\Common\Position;

interface GoToImplementationProvider
{
    /**
     * @resolve LocationLink[]
     */
    public function getLocations(Document $document, Position $position): \Generator;
}
