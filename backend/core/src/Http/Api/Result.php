<?php

namespace QCH\Core\Http\Api;

use Marcelgwerder\ApiHandler\Result as ResultBase;

class Result extends ResultBase
{
    /**
     * Get the query bulder object
     *
     * @return Illuminate\Database\Query\Builder
     */
    public function getBuilder()
    {
        if (is_null($this->parser->builder->getQuery()->columns)) {
            $this->parser->builder->addSelect('*');
        }

        return $this->parser->builder;
    }
}
