<?php declare(strict_types=1);

namespace Tsufeki\Tenkawa\Php\Reflection;

use Tsufeki\Tenkawa\Php\Reflection\Element\ClassConst;
use Tsufeki\Tenkawa\Php\Reflection\Element\ClassLike;
use Tsufeki\Tenkawa\Php\Reflection\Element\Method;
use Tsufeki\Tenkawa\Php\Reflection\Element\Property;
use Tsufeki\Tenkawa\Php\Reflection\Resolved\ResolvedClassConst;
use Tsufeki\Tenkawa\Php\Reflection\Resolved\ResolvedClassLike;
use Tsufeki\Tenkawa\Php\Reflection\Resolved\ResolvedMethod;
use Tsufeki\Tenkawa\Php\Reflection\Resolved\ResolvedProperty;
use Tsufeki\Tenkawa\Server\Document\Document;
use Tsufeki\Tenkawa\Server\Utils\Cache;
use Tsufeki\Tenkawa\Server\Utils\InfiniteRecursionMarker;

class ClassResolver
{
    /**
     * @var ReflectionProvider
     */
    private $reflectionProvider;

    /**
     * @var ClassResolverExtension[]
     */
    private $classResolverExtensions;

    private const RESOLVED_CLASS_MAP = [
        ClassConst::class => ResolvedClassConst::class,
        Property::class => ResolvedProperty::class,
        Method::class => ResolvedMethod::class,
    ];

    /**
     * @param ClassResolverExtension[] $classResolverExtensions
     */
    public function __construct(ReflectionProvider $reflectionProvider, array $classResolverExtensions)
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->classResolverExtensions = $classResolverExtensions;
    }

    /**
     * @resolve string|null
     */
    public function getParent(string $className, Document $document): \Generator
    {
        /** @var ClassLike[] $classes */
        $classes = yield $this->reflectionProvider->getClass($document, $className);

        return $classes[0]->parentClass ?? null;
    }

    /**
     * @resolve ResolvedClassLike|null
     */
    public function resolve(string $className, Document $document, ?Cache $cache = null): \Generator
    {
        $cache = $cache ?? new Cache();
        $resolved = $cache->get($className);
        if ($resolved === InfiniteRecursionMarker::get()) {
            return null;
        }
        if ($resolved !== null) {
            return $resolved;
        }
        $cache->set($className, InfiniteRecursionMarker::get());

        /** @var ClassLike[] $classes */
        $classes = yield $this->reflectionProvider->getClass($document, $className);
        if (empty($classes)) {
            return null;
        }
        $class = $classes[0];

        $resolved = new ResolvedClassLike();
        $this->copyDetails($resolved, $class);
        yield $this->resolveSupers($resolved, $class, $document, $cache);
        $this->resolveMembers($resolved, $class);

        foreach ($this->classResolverExtensions as $extension) {
            yield $extension->resolve($resolved, $document);
        }

        $cache->set($className, $resolved);

        return $resolved;
    }

    private function copyDetails(ResolvedClassLike $resolved, ClassLike $class): void
    {
        $resolved->name = $class->name;
        $resolved->location = $class->location;
        $resolved->nameRange = $class->nameRange;
        $resolved->docComment = $class->docComment;
        $resolved->nameContext = $class->nameContext;
        $resolved->isClass = $class->isClass;
        $resolved->isInterface = $class->isInterface;
        $resolved->isTrait = $class->isTrait;
        $resolved->abstract = $class->abstract;
        $resolved->final = $class->final;
        $resolved->origin = $class->origin;
    }

    private function resolveSupers(ResolvedClassLike $resolved, ClassLike $class, Document $document, Cache $cache): \Generator
    {
        if ($class->parentClass !== null) {
            $resolved->parentClass = yield $this->resolve($class->parentClass, $document, $cache);
        }

        if ($resolved->parentClass !== null) {
            $resolved->interfaces = array_merge($resolved->interfaces, $resolved->parentClass->interfaces);
        }

        foreach ($class->interfaces as $interfaceName) {
            $iface = yield $this->resolve($interfaceName, $document, $cache);
            if ($iface !== null) {
                $resolved->interfaces[] = $iface;
                $resolved->interfaces = array_merge($resolved->interfaces, $iface->interfaces);
            }
        }
        $resolved->interfaces = array_filter($resolved->interfaces);
        $interfaceNames = [];
        $resolved->interfaces = array_values(array_filter($resolved->interfaces, function (ResolvedClassLike $iface) use (&$interfaceNames) {
            $name = strtolower($iface->name);
            if (isset($interfaceNames[$name])) {
                return false;
            }
            $interfaceNames[$name] = 1;

            return true;
        }));

        foreach ($class->traits as $traitName) {
            $resolved->traits[] = yield $this->resolve($traitName, $document, $cache);
        }
        $resolved->traits = array_filter($resolved->traits);
    }

    private function resolveMembers(ResolvedClassLike $resolved, ClassLike $class): void
    {
        $resolved->methods = $this->getSelfMembers($class->methods);
        $resolved->properties = $this->getSelfMembers($class->properties);
        $resolved->consts = $this->getSelfMembers($class->consts);

        foreach ($resolved->traits as $trait) {
            $resolved->properties = $this->mergeTraitProperties($resolved->properties, $trait, $class);
            $resolved->methods = $this->mergeTraitMethods($resolved->methods, $trait, $class);
        }

        if ($resolved->parentClass !== null) {
            $resolved->methods = $this->mergeSuperMembers($resolved->methods, $resolved->parentClass->methods, $class);
            $resolved->properties = $this->mergeSuperMembers($resolved->properties, $resolved->parentClass->properties, $class);
            $resolved->consts = $this->mergeSuperMembers($resolved->consts, $resolved->parentClass->consts, $class);
        }

        foreach ($resolved->interfaces as $interface) {
            $resolved->methods = $this->mergeSuperMembers($resolved->methods, $interface->methods, $class);
            $resolved->consts = $this->mergeSuperMembers($resolved->consts, $interface->consts, $class);
        }
    }

    /**
     * @param (ResolvedClassConst|ResolvedProperty|ResolvedMethod)[] $members
     * @param (ResolvedClassConst|ResolvedProperty|ResolvedMethod)[] $superMembers
     */
    private function mergeSuperMembers(array $members, array $superMembers, ClassLike $class): array
    {
        $superMembers = array_filter($superMembers, function ($member) {
            return $member->accessibility !== ClassLike::M_PRIVATE;
        });

        return $this->mergeMembers($members, $superMembers, $class);
    }

    /**
     * @param ResolvedProperty[] $properties
     *
     * @return ResolvedProperty[]
     */
    private function mergeTraitProperties(array $properties, ResolvedClassLike $trait, ClassLike $class): array
    {
        $traitProperties = $trait->properties;

        foreach ($traitProperties as &$property) {
            $property = clone $property;
            $property->nameContext = clone $property->nameContext;
            $property->nameContext->class = $class->name;
        }
        unset($property);

        return $this->mergeMembers($properties, $traitProperties, $class);
    }

    /**
     * @param ResolvedMethod[] $methods
     *
     * @return ResolvedMethod[]
     */
    private function mergeTraitMethods(array $methods, ResolvedClassLike $trait, ClassLike $class): array
    {
        $traitName = strtolower($trait->name);
        $traitMethods = $trait->methods;

        foreach ($class->traitAliases as $alias) {
            if (($alias->trait === null || strtolower($alias->trait) === $traitName) && isset($traitMethods[strtolower($alias->method)])) {
                $method = clone $traitMethods[strtolower($alias->method)];
                $method->name = $alias->newName ?? $method->name;
                $method->accessibility = $alias->newAccessibility ?? $method->accessibility;
                $traitMethods[strtolower($method->name)] = $method;
            }
        }

        foreach ($class->traitInsteadOfs as $insteadOf) {
            foreach ($insteadOf->insteadOfs as $insteadOfTrait) {
                if (strtolower($insteadOfTrait) === $traitName) {
                    unset($traitMethods[strtolower($insteadOf->method)]);
                }
            }
        }

        foreach ($traitMethods as &$method) {
            $method = clone $method;
            $method->nameContext = clone $method->nameContext;
            $method->nameContext->class = $class->name;
        }
        unset($method);

        return $this->mergeMembers($methods, $traitMethods, $class);
    }

    /**
     * @param (ResolvedClassConst|ResolvedProperty|ResolvedMethod)[] $members
     * @param (ResolvedClassConst|ResolvedProperty|ResolvedMethod)[] $superMembers
     */
    private function mergeMembers(array $members, array $superMembers, ClassLike $class): array
    {
        foreach ($superMembers as $name => $superMember) {
            if (empty($members[$name])) {
                $members[$name] = $superMember;
            } elseif ($members[$name]->nameContext->class === $class->name) {
                $members[$name]->inheritsFrom[] = $superMember;
            }
        }

        return $members;
    }

    /**
     * @param (ClassConst|Property|Method)[] $selfMembers
     */
    private function getSelfMembers(array $selfMembers): array
    {
        /** @var (ResolvedClassConst|ResolvedProperty|ResolvedMethod)[] $resolvedMembers */
        $resolvedMembers = [];
        foreach ($selfMembers as $member) {
            $resolvedClass = self::RESOLVED_CLASS_MAP[get_class($member)];
            $resolved = new $resolvedClass();
            foreach (get_object_vars($member) as $name => $value) {
                $resolved->$name = $value;
            }

            $resolvedMembers[] = $resolved;
        }

        return $this->indexMembers($resolvedMembers);
    }

    /**
     * @param (ResolvedClassConst|ResolvedProperty|ResolvedMethod)[] $members
     */
    private function indexMembers(array $members): array
    {
        $indexedMembers = [];
        foreach ($members as $member) {
            $name = $member instanceof ResolvedMethod ? strtolower($member->name) : $member->name;
            $indexedMembers[$name] = $member;
        }

        return $indexedMembers;
    }
}
