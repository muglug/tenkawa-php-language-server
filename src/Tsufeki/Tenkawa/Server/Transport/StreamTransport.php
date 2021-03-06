<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Server\Transport;

use Recoil\Recoil;
use Tsufeki\BlancheJsonRpc\Transport\TransportMessageObserver;
use Tsufeki\Tenkawa\Server\Exception\TransportException;

class StreamTransport implements RunnableTransport
{
    /**
     * @var resource
     */
    private $readStream;

    /**
     * @var resource
     */
    private $writeStream;

    /**
     * @var string[]
     */
    private $headers;

    /**
     * @var TransportMessageObserver[]
     */
    private $observers = [];

    /**
     * @var string
     */
    private $buffer = '';

    private const EOL = "\r\n";
    private const HEADER_SEP = ': ';
    private const CONTENT_LENGTH = 'Content-Length';
    private const MAX_HEADERS_SIZE = 4096;

    /**
     * @param resource $readStream
     * @param resource $writeStream
     * @param string[] $headers
     */
    public function __construct(
        $readStream,
        $writeStream,
        array $headers = ['Content-Type' => 'application/vscode-jsonrpc; charset=utf-8']
    ) {
        $this->readStream = $readStream;
        $this->writeStream = $writeStream;
        $this->headers = $headers;
    }

    public function attach(TransportMessageObserver $observer)
    {
        $this->observers[] = $observer;
    }

    public function send(string $message): \Generator
    {
        $headers = $this->headers;
        $headers[self::CONTENT_LENGTH] = strlen($message);

        $buffer = '';
        foreach ($headers as $header => $value) {
            $buffer .= $header . self::HEADER_SEP . $value . self::EOL;
        }

        $buffer .= self::EOL . $message;

        yield Recoil::write($this->writeStream, $buffer);
    }

    /**
     * Receive one message and pass it to the observers.
     *
     * @resolve bool
     */
    public function receive(): \Generator
    {
        $headers = [];
        $size = strlen($this->buffer);

        while (true) {
            while (strpos($this->buffer, self::EOL) !== false) {
                [$line, $this->buffer] = explode(self::EOL, $this->buffer, 2);

                if ($line === '') {
                    break 2;
                }

                [$key, $value] = explode(self::HEADER_SEP, $line, 2);
                $headers[strtolower($key)] = trim($value);
            }

            $toRead = self::MAX_HEADERS_SIZE - $size;
            if ($toRead <= 0) {
                throw new TransportException('Headers too big');
            }

            $data = yield Recoil::read($this->readStream, 1, $toRead);
            if ($data === '') {
                if ($this->buffer !== '') {
                    // Partial message
                    throw new TransportException('Input stream closed');
                }

                return false;
            }

            $this->buffer .= $data;
            $size += strlen($data);
        }

        $length = (int)$headers[strtolower(self::CONTENT_LENGTH)];
        $toRead = $length - strlen($this->buffer);
        if ($toRead > 0) {
            $data = yield Recoil::read($this->readStream, $toRead, $toRead);
            if (strlen($data) < $toRead) {
                throw new TransportException('Input stream closed');
            }

            $this->buffer .= $data;
        }

        $message = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        yield Recoil::execute(array_map(function (TransportMessageObserver $observer) use ($message) {
            yield $observer->receive($message);
        }, $this->observers));

        return true;
    }

    public function run(): \Generator
    {
        while (yield $this->receive()) {
        }
        // @codeCoverageIgnoreStart
    }

    // @codeCoverageIgnoreEnd
}
