<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Parser;

class TokenIterator
{
    const WHITESPACE_TOKENS = [
        T_WHITESPACE => true,
        T_COMMENT => true,
        T_DOC_COMMENT => true,
    ];

    /**
     * @var array
     */
    private $tokens;

    /**
     * @var int
     */
    private $index = 0;

    /**
     * @var int
     */
    private $offset = 0;

    public function __construct(array $tokens, int $index = 0, int $offset = 0)
    {
        $this->tokens = $tokens;
        $this->index = $index;
        $this->offset = $offset;
    }

    /**
     * @return mixed
     */
    public function get()
    {
        return $this->tokens[$this->index] ?? [0, ''];
    }

    /**
     * @return mixed
     */
    public function getType()
    {
        return is_array($this->get()) ? $this->get()[0] : $this->get();
    }

    public function getValue(): string
    {
        return is_array($this->get()) ? $this->get()[1] : $this->get();
    }

    public function isType($tokenType): bool
    {
        return $this->getType() === $tokenType;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function eat()
    {
        $this->offset += strlen($this->getValue());
        $this->index++;
    }

    public function eatWhitespace()
    {
        while (in_array($this->getType(), self::WHITESPACE_TOKENS, true)) {
            $this->eat();
        }
    }
}