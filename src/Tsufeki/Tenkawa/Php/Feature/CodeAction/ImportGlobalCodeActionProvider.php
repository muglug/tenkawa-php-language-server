<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Php\Feature\CodeAction;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Name;
use Tsufeki\Tenkawa\Php\Feature\GlobalsHelper;
use Tsufeki\Tenkawa\Php\Feature\NodeFinder;
use Tsufeki\Tenkawa\Php\Reflection\Element\Element;
use Tsufeki\Tenkawa\Php\Reflection\NameContext;
use Tsufeki\Tenkawa\Php\Reflection\ReflectionProvider;
use Tsufeki\Tenkawa\Server\Document\Document;
use Tsufeki\Tenkawa\Server\Feature\CodeAction\CodeActionContext;
use Tsufeki\Tenkawa\Server\Feature\CodeAction\CodeActionProvider;
use Tsufeki\Tenkawa\Server\Feature\Common\Command;
use Tsufeki\Tenkawa\Server\Feature\Common\Position;
use Tsufeki\Tenkawa\Server\Feature\Common\Range;
use Tsufeki\Tenkawa\Server\Utils\PositionUtils;

class ImportGlobalCodeActionProvider implements CodeActionProvider
{
    /**
     * @var GlobalsHelper
     */
    private $globalsHelper;

    /**
     * @var NodeFinder
     */
    private $nodeFinder;

    /**
     * @var ReflectionProvider
     */
    private $reflectionProvider;

    public function __construct(GlobalsHelper $globalsHelper, NodeFinder $nodeFinder, ReflectionProvider $reflectionProvider)
    {
        $this->globalsHelper = $globalsHelper;
        $this->nodeFinder = $nodeFinder;
        $this->reflectionProvider = $reflectionProvider;
    }

    /**
     * @resolve Command[]
     */
    public function getCodeActions(Document $document, Range $range, CodeActionContext $context): \Generator
    {
        if ($document->getLanguage() !== 'php') {
            return [];
        }

        /** @var (Node|Comment)[] $nodes */
        $nodes = yield $this->nodeFinder->getNodesIntersectingWithRange($document, $range);

        $version = $document->getVersion();
        $commands = [];
        foreach ($nodes as $node) {
            if ($node instanceof Name) {
                $nodeRange = PositionUtils::rangeFromNodeAttrs($node->getAttributes(), $document);
                /** @var Command $command */
                foreach (yield $this->getCodeActionsAtPosition($nodeRange->start, $document, $version) as $command) {
                    $commands[$command->arguments[2] . '-' . $command->arguments[3]] = $command;
                }
            }
        }

        return array_values($commands);
    }

    private function getCodeActionsAtPosition(Position $position, Document $document, int $version = null): \Generator
    {
        /** @var (Node|Comment)[] $nodes */
        $nodes = yield $this->nodeFinder->getNodePath($document, $position);
        if (count($nodes) < 2 || !($nodes[0] instanceof Name)) {
            return [];
        }

        $name = $nodes[0]->getAttribute('originalName', $nodes[0]);
        $parentNode = $nodes[1];
        if (!($name instanceof Name) ||
            $name instanceof Name\FullyQualified ||
            $name instanceof Name\Relative
        ) {
            return [];
        }

        $kind = $this->getKind($parentNode);
        if ($kind === null || $this->isAlreadyImported($name, $kind)) {
            return [];
        }

        /** @var Element[] $existingElements */
        $existingElements = yield $this->globalsHelper->getReflectionFromNodePath($nodes, $document);
        if (!empty($existingElements)) {
            return [];
        }

        $elements = yield $this->getReflections($name, $kind, $document);
        $commands = [];
        /** @var Element $element */
        foreach ($elements as $element) {
            $parts = explode('\\', ltrim($element->name, '\\'));
            if (count($name->parts) > 1) {
                // discard nested parts, import only top-most namespace
                $parts = array_slice($parts, 0, -count($name->parts) + 1);
            }
            $importName = implode('\\', $parts);
            $command = new Command();
            $command->title = "Import $importName";
            $command->command = ImportCommandProvider::COMMAND;
            $command->arguments = [
                $document->getUri()->getNormalized(),
                $position,
                count($name->parts) > 1 ? '' : $kind,
                '\\' . $importName,
                $version,
            ];
            $commands[] = $command;
        }

        return $commands;
    }

    /**
     * @param Node|Comment $parentNode
     *
     * @return string|null
     */
    private function getKind($parentNode)
    {
        if (isset(GlobalsHelper::CLASS_REFERENCING_NODES[get_class($parentNode)])) {
            return '';
        }
        if (isset(GlobalsHelper::FUNCTION_REFERENCING_NODES[get_class($parentNode)])) {
            return 'function';
        }
        if (isset(GlobalsHelper::CONST_REFERENCING_NODES[get_class($parentNode)])) {
            return 'const';
        }

        return null;
    }

    private function isAlreadyImported(Name $name, string $kind): bool
    {
        $importAlias = $name->parts[0];
        /** @var NameContext $nameContext */
        $nameContext = $name->getAttribute('nameContext') ?? new NameContext();
        $kind = count($name->parts) > 1 ? '' : $kind;

        if ($kind === 'const') {
            if (isset($nameContext->constUses[$importAlias])) {
                return true;
            }
        } elseif ($kind === 'function') {
            if (isset($nameContext->functionUses[$importAlias])) {
                return true;
            }
        } else {
            if (isset($nameContext->uses[$importAlias])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @resolve Element[]
     */
    private function getReflections(Name $name, string $kind, Document $document): \Generator
    {
        if ($kind === 'const') {
            return yield $this->reflectionProvider->getConstsByShortName($document, (string)$name);
        }
        if ($kind === 'function') {
            return yield $this->reflectionProvider->getFunctionsByShortName($document, (string)$name);
        }

        return yield $this->reflectionProvider->getClassesByShortName($document, (string)$name);
    }
}
