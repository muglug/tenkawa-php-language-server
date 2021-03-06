<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Php\Feature\Hover;

use Tsufeki\Tenkawa\Php\Feature\PhpDocFormatter;
use Tsufeki\Tenkawa\Php\Reflection\Element\ClassConst;
use Tsufeki\Tenkawa\Php\Reflection\Element\ClassLike;
use Tsufeki\Tenkawa\Php\Reflection\Element\Const_;
use Tsufeki\Tenkawa\Php\Reflection\Element\Element;
use Tsufeki\Tenkawa\Php\Reflection\Element\Function_;
use Tsufeki\Tenkawa\Php\Reflection\Element\Method;
use Tsufeki\Tenkawa\Php\Reflection\Element\Param;
use Tsufeki\Tenkawa\Php\Reflection\Element\Property;
use Tsufeki\Tenkawa\Php\Reflection\Element\Type;
use Tsufeki\Tenkawa\Php\Reflection\Element\Variable;
use Tsufeki\Tenkawa\Php\TypeInference\Type as InferredType;
use Tsufeki\Tenkawa\Server\Utils\StringUtils;

class HoverFormatter
{
    /**
     * @var PhpDocFormatter
     */
    private $phpDocFormatter;

    public function __construct(PhpDocFormatter $phpDocFormatter)
    {
        $this->phpDocFormatter = $phpDocFormatter;
    }

    public function format(Element $element): string
    {
        $paragraphs = [];

        $paragraphs[0] = "```php\n<?php\n";
        if ($element instanceof ClassLike) {
            $paragraphs[0] .= $this->formatClass($element);
        } elseif ($element instanceof Method) {
            $paragraphs[0] .= $this->formatMethod($element);
        } elseif ($element instanceof Property) {
            $paragraphs[0] .= $this->formatProperty($element);
        } elseif ($element instanceof ClassConst) {
            $paragraphs[0] .= $this->formatClassConst($element);
        } elseif ($element instanceof Function_) {
            $paragraphs[0] .= $this->formatFunction($element);
        } elseif ($element instanceof Variable) {
            $paragraphs[0] .= $this->formatVariable($element);
        } elseif ($element instanceof Const_) {
            $paragraphs[0] .= $this->formatConst($element);
        }
        $paragraphs[0] .= "\n```";

        $namespace = $element->nameContext->namespace;
        if ($namespace !== '\\') {
            $namespace = trim($namespace, '\\');
            $paragraphs[] = "in `$namespace`";
        }

        if ($element->docComment) {
            $paragraphs[] = $this->phpDocFormatter->format($element->docComment->text, $element->nameContext);
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * @param Property|Method|ClassConst $member
     */
    private function formatModifiers($member): string
    {
        $s = '';
        if ($member->accessibility === ClassLike::M_PUBLIC) {
            $s .= 'public ';
        } elseif ($member->accessibility === ClassLike::M_PROTECTED) {
            $s .= 'protected ';
        } elseif ($member->accessibility === ClassLike::M_PRIVATE) {
            $s .= 'private ';
        }

        if ($member->static) {
            $s .= 'static ';
        }

        return $s;
    }

    private function replaceAnonymousClass(string $str): string
    {
        return StringUtils::replace('/\\\\?Anonymous@[0-9a-f]+/', '<anonymous_class>', $str);
    }

    private function formatClassPrefix(?string $class): string
    {
        if (!$class) {
            return '';
        }

        return $this->replaceAnonymousClass(StringUtils::getShortName($class)) . '::';
    }

    private function formatVariable(Variable $variable, ?string $class = null): string
    {
        return $this->formatClassPrefix($class) . '$' . $variable->name;
    }

    private function formatProperty(Property $property): string
    {
        return $this->formatModifiers($property) . $this->formatVariable($property, $property->nameContext->class);
    }

    private function formatConst(Const_ $const, ?string $class = null): string
    {
        $s = 'const ' . $this->formatClassPrefix($class) . StringUtils::getShortName($const->name);
        if ($const->valueExpression !== null) {
            $s .= ' = ' . StringUtils::limitLength($const->valueExpression);
        }

        return $s;
    }

    private function formatClassConst(ClassConst $const): string
    {
        $s = '';
        if ($const->accessibility === ClassLike::M_PROTECTED) {
            $s .= 'protected ';
        } elseif ($const->accessibility === ClassLike::M_PRIVATE) {
            $s .= 'private ';
        }

        return $s . $this->formatConst($const, $const->nameContext->class);
    }

    private function formatFunction(Function_ $function, ?string $class = null): string
    {
        $s = 'function ';
        if ($function->returnByRef) {
            $s .= '&';
        }

        $s .= $this->formatClassPrefix($class) . StringUtils::getShortName($function->name);

        $params = array_map([$this, 'formatParam'], $function->params);
        if ($function->callsFuncGetArgs) {
            $params[] = '...';
        }
        if (empty($params)) {
            $s .= '()';
        } else {
            $s .= "(\n  " . implode(",\n  ", $params) . "\n)";
        }

        if ($function->returnType !== null) {
            $s .= ': ' . $this->formatType($function->returnType);
        }

        return $s;
    }

    public function formatParam(Param $param): string
    {
        $s = '';
        if ($param->type !== null) {
            $s .= $this->formatType($param->type) . ' ';
        }
        if ($param->byRef) {
            $s .= '&';
        }
        if ($param->variadic) {
            $s .= '...';
        }
        $s .= '$' . $param->name;
        if ($param->defaultNull) {
            $s .= ' = null';
        } elseif ($param->defaultExpression) {
            $s .= ' = ' . StringUtils::limitLength($param->defaultExpression);
        } elseif ($param->optional && !$param->variadic) {
            $s .= ' = ...';
        }

        return $s;
    }

    private function formatType(Type $type): string
    {
        $s = '';
        $identifier = $type->type;

        if (($identifier[0] ?? '') === '?') {
            $identifier = substr($identifier, 1);
            $s = '?';
        }

        $s .= $this->replaceAnonymousClass(StringUtils::getShortName($identifier));

        return $s;
    }

    private function formatMethod(Method $method): string
    {
        $s = '';
        if ($method->abstract) {
            $s .= 'abstract ';
        }
        if ($method->final) {
            $s .= 'final ';
        }

        $s .= $this->formatModifiers($method);
        $s .= $this->formatFunction($method, $method->nameContext->class);

        return $s;
    }

    private function formatClass(ClassLike $class): string
    {
        $s = '';
        if ($class->abstract) {
            $s .= 'abstract ';
        }
        if ($class->final) {
            $s .= 'final ';
        }

        if ($class->isClass) {
            $s .= 'class ' . StringUtils::getShortName($class->name);
            if ($class->parentClass !== null) {
                $s .= ' extends ' . StringUtils::getShortName($class->parentClass);
            }
            if ($class->interfaces !== []) {
                $s .= ' implements ' . implode(', ', array_map(
                    [StringUtils::class, 'getShortName'],
                    $class->interfaces
                ));
            }
        } elseif ($class->isInterface) {
            $s .= 'interface ' . StringUtils::getShortName($class->name);
            if ($class->interfaces !== []) {
                $s .= ' extends ' . implode(', ', array_map(
                    [StringUtils::class, 'getShortName'],
                    $class->interfaces
                ));
            }
        } elseif ($class->isTrait) {
            $s .= 'trait ' . StringUtils::getShortName($class->name);
        }

        return $s;
    }

    public function formatExpression(InferredType $type): string
    {
        $typeString = $this->replaceAnonymousClass((string)$type);

        return "expression: `$typeString`";
    }
}
