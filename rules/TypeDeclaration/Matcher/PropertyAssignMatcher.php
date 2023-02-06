<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\Matcher;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use Rector\Core\NodeAnalyzer\LocalPropertyFetchAnalyzer;

final class PropertyAssignMatcher
{
    public function __construct(
        private readonly LocalPropertyFetchAnalyzer $localPropertyFetchAnalyzer
    ) {
    }

    /**
     * Covers:
     * - $this->propertyName = $expr;
     * - $this->propertyName[] = $expr;
     */
    public function matchPropertyAssignExpr(Assign $assign, string $propertyName): ?Expr
    {
        $assignVar = $assign->var;
        if ($this->localPropertyFetchAnalyzer->isLocalPropertyFetchName($assignVar, $propertyName)) {
            return $assign->expr;
        }

        if (! $assignVar instanceof ArrayDimFetch) {
            return null;
        }

        if ($this->localPropertyFetchAnalyzer->isLocalPropertyFetchName($assignVar->var, $propertyName)) {
            return $assign->expr;
        }

        return null;
    }
}
