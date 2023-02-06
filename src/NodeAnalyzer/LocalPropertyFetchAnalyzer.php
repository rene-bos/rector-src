<?php

declare(strict_types=1);

namespace Rector\Core\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Type\ThisType;
use Rector\Core\Enum\ObjectReference;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\PhpDocParser\NodeTraverser\SimpleCallableNodeTraverser;
use Rector\StaticTypeMapper\ValueObject\Type\FullyQualifiedObjectType;

final class LocalPropertyFetchAnalyzer
{
    /**
     * @var string
     */
    private const THIS = 'this';

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly SimpleCallableNodeTraverser $simpleCallableNodeTraverser,
        private readonly NodeTypeResolver $nodeTypeResolver
    ) {
    }

    public function isLocalPropertyFetch(Node $node): bool
    {
        if (! $node instanceof PropertyFetch && ! $node instanceof StaticPropertyFetch) {
            return false;
        }

        $variableType = $node instanceof PropertyFetch
            ? $this->nodeTypeResolver->getType($node->var)
            : $this->nodeTypeResolver->getType($node->class);

        if ($variableType instanceof FullyQualifiedObjectType) {
            $currentClassLike = $this->betterNodeFinder->findParentType($node, ClassLike::class);
            if ($currentClassLike instanceof ClassLike) {
                return $this->nodeNameResolver->isName($currentClassLike, $variableType->getClassName());
            }

            return false;
        }

        if (! $variableType instanceof ThisType) {
            return $this->isTraitLocalPropertyFetch($node);
        }

        return true;
    }

    public function isLocalPropertyFetchName(Node $node, string $desiredPropertyName): bool
    {
        if (! $this->isLocalPropertyFetch($node)) {
            return false;
        }

        /** @var PropertyFetch|StaticPropertyFetch $node */
        return $this->nodeNameResolver->isName($node->name, $desiredPropertyName);
    }

    public function countLocalPropertyFetchName(Class_ $class, string $propertyName): int
    {
        $total = 0;

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($class->stmts, function (Node $subNode) use (
            $class,
            $propertyName,
            &$total
        ): ?Node {
            if (! $this->isLocalPropertyFetchName($subNode, $propertyName)) {
                return null;
            }

            $parentClassLike = $this->betterNodeFinder->findParentType($subNode, ClassLike::class);

            // property fetch in Trait cannot get parent ClassLike
            if (! $parentClassLike instanceof ClassLike) {
                ++$total;
            }

            if ($parentClassLike === $class) {
                ++$total;
            }

            return $subNode;
        });

        return $total;
    }

    public function containsLocalPropertyFetchName(Trait_ $trait, string $propertyName): bool
    {
        if ($trait->getProperty($propertyName) instanceof Property) {
            return true;
        }

        return (bool) $this->betterNodeFinder->findFirst(
            $trait,
            fn (Node $node): bool => $this->isLocalPropertyFetchName($node, $propertyName)
        );
    }

    /**
     * @param string[] $propertyNames
     */
    public function isLocalPropertyOfNames(Expr $expr, array $propertyNames): bool
    {
        if (! $this->isLocalPropertyFetch($expr)) {
            return false;
        }

        /** @var PropertyFetch $expr */
        return $this->nodeNameResolver->isNames($expr->name, $propertyNames);
    }

    /**
     * Matches:
     * "$this->someValue = $<variableName>;"
     */
    public function isVariableAssignToThisPropertyFetch(Assign $assign, string $variableName): bool
    {
        if (! $assign->expr instanceof Variable) {
            return false;
        }

        if (! $this->nodeNameResolver->isName($assign->expr, $variableName)) {
            return false;
        }

        return $this->isLocalPropertyFetch($assign->var);
    }

    private function isTraitLocalPropertyFetch(Node $node): bool
    {
        if ($node instanceof PropertyFetch) {
            if (! $node->var instanceof Variable) {
                return false;
            }

            return $this->nodeNameResolver->isName($node->var, self::THIS);
        }

        if ($node instanceof StaticPropertyFetch) {
            if (! $node->class instanceof Name) {
                return false;
            }

            return $this->nodeNameResolver->isNames($node->class, [
                ObjectReference::SELF,
                ObjectReference::STATIC,
            ]);
        }

        return false;
    }
}
