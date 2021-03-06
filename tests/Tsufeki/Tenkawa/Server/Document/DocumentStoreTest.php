<?php declare(strict_types=1);

namespace Tests\Tsufeki\Tenkawa\Server\Document;

use PHPUnit\Framework\TestCase;
use Recoil\React\ReactKernel;
use Tsufeki\Tenkawa\Server\Document\Document;
use Tsufeki\Tenkawa\Server\Document\DocumentStore;
use Tsufeki\Tenkawa\Server\Document\Project;
use Tsufeki\Tenkawa\Server\Event\Document\OnChange;
use Tsufeki\Tenkawa\Server\Event\Document\OnClose;
use Tsufeki\Tenkawa\Server\Event\Document\OnOpen;
use Tsufeki\Tenkawa\Server\Event\Document\OnProjectClose;
use Tsufeki\Tenkawa\Server\Event\Document\OnProjectOpen;
use Tsufeki\Tenkawa\Server\Event\EventDispatcher;
use Tsufeki\Tenkawa\Server\Exception\DocumentNotOpenException;
use Tsufeki\Tenkawa\Server\Exception\ProjectNotOpenException;
use Tsufeki\Tenkawa\Server\Uri;

/**
 * @covers \Tsufeki\Tenkawa\Server\Document\DocumentStore
 * @covers \Tsufeki\Tenkawa\Server\Document\Document
 * @covers \Tsufeki\Tenkawa\Server\Document\Project
 */
class DocumentStoreTest extends TestCase
{
    public function test_document()
    {
        ReactKernel::start(function () {
            $dispatcher = $this->createMock(EventDispatcher::class);
            $dispatcher
                ->expects($this->exactly(4))
                ->method('dispatchAndWait')
                ->withConsecutive(
                    [$this->identicalTo(OnProjectOpen::class), $this->isInstanceOf(Project::class)],
                    [$this->identicalTo(OnOpen::class), $this->isInstanceOf(Document::class)],
                    [$this->identicalTo(OnChange::class), $this->isInstanceOf(Document::class)],
                    [$this->identicalTo(OnClose::class), $this->isInstanceOf(Document::class)]
                );

            $uri = Uri::fromString('file:///foo');
            $store = new DocumentStore($dispatcher);
            $project = yield $store->openProject(Uri::fromString('file:///'));

            $document = yield $store->open($uri, 'php', '<?php', 42);

            $this->assertSame($uri, $document->getUri());
            $this->assertSame('php', $document->getLanguage());
            $this->assertSame('<?php', $document->getText());
            $this->assertSame(42, $document->getVersion());

            $this->assertSame($document, $store->get($uri));

            yield $store->update($document, '<?php 43;', 43);

            $this->assertSame('<?php 43;', $document->getText());
            $this->assertSame(43, $document->getVersion());

            yield $store->close($document);

            $this->assertTrue($document->isClosed());
        });
    }

    public function test_project()
    {
        ReactKernel::start(function () {
            $dispatcher = $this->createMock(EventDispatcher::class);
            $dispatcher
                ->expects($this->exactly(2))
                ->method('dispatchAndWait')
                ->withConsecutive(
                    [$this->identicalTo(OnProjectOpen::class), $this->isInstanceOf(Project::class)],
                    [$this->identicalTo(OnProjectClose::class), $this->isInstanceOf(Project::class)]
                );

            $uri = Uri::fromString('file:///foo');
            $store = new DocumentStore($dispatcher);
            $project = yield $store->openProject($uri);

            $this->assertSame($uri, $project->getRootUri());
            $this->assertSame($project, $store->getProject($uri));

            yield $store->closeProject($project);

            $this->assertTrue($project->isClosed());
        });
    }

    public function test_close_all()
    {
        ReactKernel::start(function () {
            $dispatcher = $this->createMock(EventDispatcher::class);
            $dispatcher
                ->expects($this->exactly(4))
                ->method('dispatchAndWait')
                ->withConsecutive(
                    [$this->identicalTo(OnProjectOpen::class), $this->isInstanceOf(Project::class)],
                    [$this->identicalTo(OnOpen::class), $this->isInstanceOf(Document::class)],
                    [$this->identicalTo(OnClose::class), $this->isInstanceOf(Document::class)],
                    [$this->identicalTo(OnProjectClose::class), $this->isInstanceOf(Project::class)]
                );

            $store = new DocumentStore($dispatcher);
            $project = yield $store->openProject(Uri::fromString('file:///'));
            $document = yield $store->open(Uri::fromString('file:///foo'), 'php', '<?php', 42);

            yield $store->closeAll();
        });
    }

    public function test_document_load()
    {
        ReactKernel::start(function () {
            $dispatcher = $this->createMock(EventDispatcher::class);
            $dispatcher
                ->expects($this->once())
                ->method('dispatchAndWait')
                ->with($this->identicalTo(OnProjectOpen::class), $this->isInstanceOf(Project::class));

            $uri = Uri::fromString('file:///foo');
            $store = new DocumentStore($dispatcher);
            $project = yield $store->openProject(Uri::fromString('file:///'));

            $document = yield $store->load($uri, 'php', '<?php');

            $this->assertSame($uri, $document->getUri());
            $this->assertSame('php', $document->getLanguage());
            $this->assertSame('<?php', $document->getText());
            $this->assertNull($document->getVersion());
        });
    }

    public function test_project_not_open()
    {
        $dispatcher = $this->createMock(EventDispatcher::class);

        $uri = Uri::fromString('file:///foo');
        $store = new DocumentStore($dispatcher);

        $this->expectException(ProjectNotOpenException::class);
        $store->getProject($uri);
    }

    public function test_document_not_open()
    {
        $dispatcher = $this->createMock(EventDispatcher::class);

        $uri = Uri::fromString('file:///foo');
        $store = new DocumentStore($dispatcher);

        $this->expectException(DocumentNotOpenException::class);
        $store->get($uri);
    }
}
