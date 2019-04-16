<?php

namespace Doctrine\ORM\EXSystPatches;

use Doctrine\Common\Collections\Expr\ExpressionVisitor;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\Common\Collections\Expr\CompositeExpression;
use Doctrine\Common\Collections\Expr\Value;
use Doctrine\ORM\Mapping\ClassMetadata;

class SqlWhereVisitor extends ExpressionVisitor
{
    private $quoteStrategy;
    private $classMetadata;
    private $platform;
    private $tableAlias;
    private $params;

    public function __construct(QuoteStrategy $quoteStrategy, ClassMetadata $classMetadata, AbstractPlatform $platform, string $tableAlias, array &$params)
    {
        $this->quoteStrategy = $quoteStrategy;
        $this->classMetadata = $classMetadata;
        $this->platform = $platform;
        $this->tableAlias = $tableAlias;
        $this->params = &$params;
    }

    /**
     * Converts a comparison expression into the target query language output.
     *
     * @param Comparison $comparison
     *
     * @return mixed
     */
    public function walkComparison(Comparison $comparison)
    {
        $field = $comparison->getField();
        $value = $this->walkValue($comparison->getValue(), $comparison);

        $operator = $comparison->getOperator();
        if ($operator === Comparison::CONTAINS) {
            $operator = 'LIKE';
        } elseif ($operator === Comparison::NIN) {
            $operator = 'NOT IN';
        } elseif ($operator === Comparison::EQ && $value === 'NULL') {
            $operator = 'IS';
        } elseif ($operator === Comparison::NEQ && $value === 'NULL') {
            $operator = 'IS NOT';
        }

        return sprintf('te.%s %s %s', $this->quoteStrategy->getColumnName($field, $this->classMetadata, $this->platform), $operator, $value);
    }

    /**
     * Converts a composite expression into the target query language output.
     *
     * @param CompositeExpression $expr
     *
     * @return mixed
     *
     * @throws \RuntimeException
     */
    public function walkCompositeExpression(CompositeExpression $expr)
    {
        $expressionList = array();

        foreach ($expr->getExpressionList() as $child) {
            $expressionList[] = $this->dispatch($child);
        }

        switch($expr->getType()) {
            case CompositeExpression::TYPE_AND:
                return '(' . implode(' AND ', $expressionList) . ')';

            case CompositeExpression::TYPE_OR:
                return '(' . implode(' OR ', $expressionList) . ')';

            default:
                throw new \RuntimeException("Unknown composite " . $expr->getType());
        }
    }

    /**
     * Converts a value expression into the target query language part.
     *
     * @param Value $value
     *
     * @return mixed
     */
    public function walkValue(Value $value, ?Comparison $comparison = null)
    {
        $value = $value->getValue();
        if ($value === null) {
            return 'NULL';
        }

        if (is_object($value)) {
            $value = $value->getId(); // HACK
        } elseif (is_array($value)) {
            foreach ($value as &$subValue) {
                if (is_object($subValue)) {
                    $subValue = $subValue->getId(); // HACK
                }
                $this->params[] = $subValue;
                $subValue = '?';
            }
            unset($subValue);

            return '('.implode(', ', $value).')';
        }

        if ($comparison !== null && $comparison->getOperator() === Comparison::CONTAINS) {
            $value = '%'.$value.'%';
        }

        $this->params[] = $value;
        return '?';
    }
}