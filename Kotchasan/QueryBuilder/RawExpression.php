<?php

namespace Kotchasan\QueryBuilder;

class RawExpression
{
    private string $sql;

    /**
     * @param string $sql
     */
    public function __construct(string $sql)
    {
        $this->sql = $sql;
    }

    /**
     * @return mixed
     */
    public function toSql(): string
    {
        return $this->sql;
    }

    /**
     * @return mixed
     */
    public function __toString(): string
    {
        return $this->sql;
    }
}
