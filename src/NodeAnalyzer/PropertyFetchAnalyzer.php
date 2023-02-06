<?php

declare(strict_types=1);

namespace Rector\Core\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Type\ObjectType;
use Rector\Core\PhpParser\AstResolver;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\Core\ValueObject\MethodName;
use Rector\NodeNameResolver\NodeNameResolver;

final class PropertyFetchAnalyzer
{
    /**
     * @var string
     */
    private const THIS = 'this';

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly AstResolver $astResolver,
        private readonly LocalPropertyFetchAnalyzer $localPropertyFetchAnalyzer,
    ) {
    }

    public function isPropertyFetch(Node $node): bool
    {
        if ($node instanceof PropertyFetch) {
            return true;
        }

        return $node instanceof StaticPropertyFetch;
    }

    public function isPropertyToSelf(PropertyFetch $propertyFetch): bool
    {
        if (! $this->nodeNameResolver->isName($propertyFetch->var, self::THIS)) {
            return false;
        }

        $class = $this->betterNodeFinder->findParentType($propertyFetch, Class_::class);
        if (! $class instanceof Class_) {
            return false;
        }

        foreach ($class->getProperties() as $property) {
            if (! $this->nodeNameResolver->areNamesEqual($property->props[0], $propertyFetch)) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function isFilledViaMethodCallInConstructStmts(ClassLike $classLike, string $propertyName): bool
    {
        $classMethod = $classLike->getMethod(MethodName::CONSTRUCT);
        if (! $classMethod instanceof ClassMethod) {
            return false;
        }

        $className = (string) $this->nodeNameResolver->getName($classLike);
        $stmts = (array) $classMethod->stmts;

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }

            if (! $stmt->expr instanceof MethodCall && ! $stmt->expr instanceof StaticCall) {
                continue;
            }

            $callerClassMethod = $this->astResolver->resolveClassMethodFromCall($stmt->expr);
            if (! $callerClassMethod instanceof ClassMethod) {
                continue;
            }

            $callerClass = $this->betterNodeFinder->findParentType($callerClassMethod, Class_::class);
            if (! $callerClass instanceof Class_) {
                continue;
            }

            $callerClassName = (string) $this->nodeNameResolver->getName($callerClass);
            $isFound = $this->isPropertyAssignFoundInClassMethod(
                $classLike,
                $className,
                $callerClassName,
                $callerClassMethod,
                $propertyName
            );
            if ($isFound) {
                return true;
            }
        }

        return false;
    }

    private function isPropertyAssignFoundInClassMethod(
        ClassLike $classLike,
        string $className,
        string $callerClassName,
        ClassMethod $classMethod,
        string $propertyName
    ): bool {
        if ($className !== $callerClassName && ! $classLike instanceof Trait_) {
            $objectType = new ObjectType($className);
            $callerObjectType = new ObjectType($callerClassName);

            if (! $callerObjectType->isSuperTypeOf($objectType)->yes()) {
                return false;
            }
        }

        foreach ((array) $classMethod->stmts as $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }

            if (! $stmt->expr instanceof Assign) {
                continue;
            }

            if ($this->localPropertyFetchAnalyzer->isLocalPropertyFetchName($stmt->expr->var, $propertyName)) {
                return true;
            }
        }

        return false;
    }
}
