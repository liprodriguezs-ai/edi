<?php

namespace QCH\Core\Http\Api;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Marcelgwerder\ApiHandler\ApiHandlerException;
use Marcelgwerder\ApiHandler\Parser as ParserBase;

class Parser extends ParserBase
{
    /**
     * The builder Instance.
     *
     * @var mixed
     */
    public $builder;

    /**
     * The original builder Instance.
     *
     * @var mixed
     */
    public $originalBuilder;

    /**
     * The parsed meta information.
     *
     * @var array
     */
    public $meta = [];

    /**
     * If the parser works on multiple datasets.
     *
     * @var bool
     */
    public $multiple;

    /**
     * The mode for the response (count,default).
     *
     * @var string
     */
    public $mode = 'default';

    /**
     * If the response should be in an envelope or not.
     *
     * @var bool
     */
    public $envelope;

    /**
     * The base query builder instance.
     *
     * @var Builder
     */
    protected $query;

    /**
     * The original query builder instance.
     *
     * @var Builder
     */
    protected $originalQuery;

    /**
     * The http query params.
     *
     * @var array
     */
    protected $params;

    /**
     * Predefined functions.
     *
     * @var array
     */
    protected $functions = [
        'fullTextSearchColumns',
        'active',
        'inactive',
        'page',
        'fields',
        'sort',
        'sortJoin',
        'limit',
        'offset',
        'config',
        'with',
        'withJoin',
        'withCount',
        'q',
        'rand',
    ];

    /**
     * All functional params.
     */
    protected $functionalParams;

    /**
     * All fields that belong to a relation.
     *
     * @var array
     */
    protected $additionalFields = [];

    /**
     * All sorts that belong to a relation.
     *
     * @var array
     */
    protected $additionalSorts = [];

    /**
     * If builder is an eloquent builder or not.
     *
     * @var bool
     */
    protected $isEloquentBuilder = false;

    /**
     * If builder is an query builder or not.
     *
     * @var bool
     */
    protected $isQueryBuilder = false;

    protected function parseBoolean($value)
    {
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            default:
                return $value;
        }
    }

    /**
     * Parse the query parameters with the given options.
     * Either for a single dataset or multiple.
     *
     * @param  mixed  $options
     * @param  bool  $multiple
     */
    public function parse($options, $multiple = false)
    {
        $this->multiple = $multiple;

        if ($multiple) {
            $fullTextSearchColumns = array_map([$this, 'getQualifiedColumnName'], $options ?? []);

            // Parse and apply offset using the laravel "offset" function
            if ($offset = $this->getParam('offset')) {
                $offset = intval($offset);
                $this->query->offset($offset);
            }

            if ($offset = $this->getParam('rand')) {
                $this->query->inRandomOrder($offset);
            }

            // Parse and apply limit using the laravel "limit" function
            if ($limit = $this->getParam('limit')) {
                $limit = intval($limit);
                $this->query->limit($limit);
            }

            // Parse and apply the filters using the different laravel "where" functions
            // Every parameter that has not a predefined functionality gets parsed as a filter
            if ($filterParams = $this->getFilterParams()) {
                $this->parseFilter($filterParams);
            }

            // Parse an apply the fulltext search using the different laravel "where" functions
            // The fulltext search is only applied to the columns passed by $fullTextSearchColumns.
            if ($this->getParam('q') !== false) {
                $this->parseFulltextSearch($this->getParam('q'), $fullTextSearchColumns);
            }
        } else {
            $identification = $options;

            if (is_array($identification)) {
                foreach ($identification as $column => $value) {
                    $this->query->where($column, $this->parseBoolean($value));
                }
            } else {
                if ($this->isEloquentBuilder) {
                    $primaryKey = $this->builder->getModel()->getQualifiedKeyName();
                } else {
                    $primaryKey = $this->getQualifiedColumnName('id');
                }

                $this->query->where($primaryKey, $this->parseBoolean($identification));
            }
        }

        // Parse and apply field elements using the laravel "select" function
        // The needed fields for the with function (Primary and foreign keys) have to be added accordingly
        if ($fields = $this->getParam('fields')) {
            $this->parseFields($fields);
        }

        // Parse and apply sort elements using the laravel "orderBy" function
        if ($sort = $this->getParam('sort')) {
            $this->parseSort($sort);
        }

        // Parse and apply sort elements using the laravel "orderBy" function
        if ($sort = $this->getParam('sortJoin')) {
            $this->parseSortJoin($sort);
        }

        // Parse and apply with elements using the Laravel "with" function
        if (($with = $this->getParam('with')) && $this->isEloquentBuilder) {
            $this->parseWith($with);
        }

        // Parse and apply with elements using the Laravel "with" function
        if (($withCount = $this->getParam('withCount')) && $this->isEloquentBuilder) {
            $this->parseWith($withCount, true);
        }

        // Parse and apply with elements using the Laravel "with" function
        if (($with = $this->getParam('withJoin')) && $this->isEloquentBuilder) {
            $this->parseWithJoin($with);
        }

        // Parse the config params
        if ($config = $this->getParam('config')) {
            $this->parseConfig($config);
        }

        if ($this->isEloquentBuilder) {
            // Attach the query builder object back to the eloquent builder object
            $this->builder->setQuery($this->query);
        }

        $active = $this->getParam('active');
        if (gettype($active) == 'boolean') {
            $this->builder->withTrashed();
        }

        if ($active === 'false') {
            $this->builder->onlyTrashed();
        }
    }

    /**
     * Parse the sort param and determine whether the sorting is ascending or descending.
     * A descending sort has a leading "-". Apply it to the query.
     *
     * @param  string  $sortParam
     * @return void
     */
    protected function parseSortJoin($sortParam)
    {
        foreach (explode(',', $sortParam) as $sortElem) {
            // Check if ascending or derscenting(-) sort
            $direction = 'asc';
            if (preg_match('/^-.+/', $sortElem)) {
                $direction = 'desc';
            }

            $column = $this->getQualifiedColumnName(preg_replace('/^-/', '', $sortElem));
            $pair = [$column, $direction];

            // Only add the sorts that are on the base resource
            call_user_func_array([$this->query, 'orderBy'], $pair);
        }
    }

    protected function parseWithJoin($withParam)
    {
        $baseModel = $this->builder->getModel();
        $tableParent = $baseModel->getTable();
        $this->builder->addSelect("{$tableParent}.*");
        foreach (explode(',', $withParam) as $with) {
            $relation = $baseModel->$with();
            $relatedModel = $relation->getRelated();
            $table = $relatedModel->getTable();
            $this->builder->withJoinLeft($with);
            foreach ($relatedModel->getFillable() as $fillable) {
                $this->builder->addSelect("{$table}.id AS {$with}.id");
                $this->builder->addSelect("{$table}.{$fillable} AS {$with}.{$fillable}");
            }
        }

        return $baseModel;
    }

    /**
     * Parse the remaining filter params
     *
     * @param  array  $filterParams
     * @return void
     */
    protected function parseFilter($filterParams)
    {
        $supportedPostfixes = [
            'st' => '<',
            'gt' => '>',
            'min' => '>=',
            'max' => '<=',
            'lk' => 'LIKE',
            'not-lk' => 'NOT LIKE',
            'in' => 'IN',
            'not-in' => 'NOT IN',
            'not' => '!=',
        ];

        $supportedPrefixesStr = implode('|', $supportedPostfixes);
        $supportedPostfixesStr = implode('|', array_keys($supportedPostfixes));

        foreach ($filterParams as $filterParamKey => $filterParamValue) {
            if ($filterParamKey[0] == '_') {
                continue;
            }
            $keyMatches = [];

            // Matches every parameter with an optional prefix and/or postfix
            // e.g. not-title-lk, title-lk, not-title, title
            $keyRegex = '/^(?:('.$supportedPrefixesStr.')-)?(.*?)(?:-('.$supportedPostfixesStr.')|$)/';

            preg_match($keyRegex, $filterParamKey, $keyMatches);

            if (! isset($keyMatches[3])) {
                if (strtolower(trim($filterParamValue)) == 'null') {
                    $comparator = 'NULL';
                } else {
                    $comparator = '=';
                }
            } else {
                if (strtolower(trim($filterParamValue)) == 'null') {
                    $comparator = 'NOT NULL';
                } else {
                    $comparator = $supportedPostfixes[$keyMatches[3]];
                }
            }

            $column = $this->getQualifiedColumnName($keyMatches[2]);

            if ($comparator == 'IN') {
                $values = explode(',', $filterParamValue);

                $this->query->whereIn($column, $values);
            } else {
                if ($comparator == 'NOT IN') {
                    $values = explode(',', $filterParamValue);

                    $this->query->whereNotIn($column, $values);
                } else {
                    $values = explode('|', $filterParamValue);

                    if (count($values) > 1) {
                        $this->query->where(function ($query) use ($column, $comparator, $values) {
                            foreach ($values as $value) {
                                if ($comparator == 'LIKE' || $comparator == 'NOT LIKE') {
                                    $value = preg_replace('/(^\*|\*$)/', '%', $value);
                                }

                                // Link the filters with AND of there is a "not" and with OR if there's none
                                if ($comparator == '!=' || $comparator == 'NOT LIKE') {
                                    $query->where($column, $comparator, $value);
                                } else {
                                    $query->orWhere($column, $comparator, $value);
                                }
                            }
                        });
                    } else {
                        $value = $values[0];
                        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value)) {
                            $value = Carbon::createFromFormat('d/m/Y', $value)->format('Y/m/d');
                        }
                        if ($comparator == 'LIKE' || $comparator == 'NOT LIKE') {
                            $value = preg_replace('/(^\*|\*$)/', '%', $value);
                        }

                        if ($comparator == 'NULL' || $comparator == 'NOT NULL') {
                            $this->query->whereNull($column, 'and', $comparator == 'NOT NULL');
                        } else {
                            $this->query->where($column, $comparator, $this->parseBoolean($value));
                        }
                    }
                }
            }
        }
    }

    /**
     * Parse the fulltext search parameter q
     *
     * @param  string  $qParam
     * @param  array  $fullTextSearchColumns
     * @return void
     */
    protected function parseFullTextSearch($qParam, $fullTextSearchColumns)
    {
        if ($qParam == '') {
            // Add where that will never be true
            $this->query->whereRaw('0 = 1');

            return;
        }

        $fulltextType = Config::get('apihandler.fulltext');
        if ($fulltextType == 'native') {
            // Use pdo's quote method to be protected against sql-injections.
            // The usual placeholders unfortunately don't seem to work using AGAINST().
            $qParam = $this->query->getConnection()->getPdo()->quote($qParam);

            // Use native fulltext search
            $this->query->whereRaw('MATCH('.implode(
                ',',
                $fullTextSearchColumns
            ).') AGAINST("*'.$qParam.'*" IN BOOLEAN MODE)');

            // Add the * to the selects because of the score column
            /*if (count($this->query->columns) == 0) {
                $this->query->addSelect('*');
            }*/

            // Add the score column
            $scoreColumn = Config::get('apihandler.fulltext_score_column');

            // $this->query->addSelect($this->query->raw('MATCH(' . implode(',',
            // $fullTextSearchColumns) . ') AGAINST(' . $qParam . ' IN BOOLEAN MODE) as `' . $scoreColumn . '`'));
            return true;
        }

        $keywords = preg_replace('/\s+/', '%', $qParam);
        // $keywords = explode(' ', $qParam);
        // $collumns = implode(",", $fullTextSearchColumns);
        // Use default php implementation
        // $this->query->whereRaw("CONCAT($collumns) LIKE '%{$keywords}%'");
        $this->query->where(function ($query) use ($fullTextSearchColumns, $keywords) {
            foreach ($fullTextSearchColumns as $column) {
                $query->orWhere($column, 'LIKE', '%'.$keywords.'%');
            }
        });
    }

    /**
     * Get the qualified column name
     *
     * @param  string  $column
     * @param  null  $table
     * @return string
     */
    protected function getQualifiedColumnName($column, $table = null)
    {
        // Check whether there is a matching column expression that contains an
        // alias and should therefore not be turned into a qualified column name.
        $isAlias = count(array_filter($this->query->columns ?: [], function ($queryColumn) use ($column) {
            return preg_match('/.*[\s\'"`]as\s*[\s\'"`]'.$column.'[\'"`]?$/', trim($queryColumn));
        })) > 0;

        if (strpos($column, '*') !== false && ! $isAlias) {
            return str_replace('*', '.', $column);
        } elseif (strpos($column, '.') === false && ! $isAlias) {
            return $table ?: $this->query->from.'.'.$column;
        }

        return $column;
    }

    /**
     * Parse the with parameter
     *
     * @param  string  $withParam
     * @return void
     */
    protected function parseWith($withParam, $count = false)
    {
        $fields = $this->query->columns;
        $fieldsCount = count($fields ?: []);
        $baseModel = $this->builder->getModel();

        $withHistory = [];

        foreach (explode(',', $withParam) as $with) {
            // Use ArrayObject to be able to copy the array (for array_splice)
            $parts = new \ArrayObject(explode('.', $with));
            $lastKey = count($parts) - 1;

            for ($i = 0; $i <= $lastKey; $i++) {
                $part = $parts[$i];
                $partsCopy = $parts->getArrayCopy();

                // Get the previous history path (e.g. if current is a.b.c the previous is a.b)
                $previousHistoryPath = implode('.', array_splice($partsCopy, 0, $i));

                // Get the current history part based on the previous one
                $currentHistoryPath = $previousHistoryPath ? $previousHistoryPath.'.'.$part : $part;

                // Create new history element
                if (! isset($withHistory[$currentHistoryPath])) {
                    $withHistory[$currentHistoryPath] = ['fields' => []];
                }

                // Get all given fields related to the current part
                $withHistory[$currentHistoryPath]['fields'] = array_filter(
                    $this->additionalFields,
                    function ($field) use ($part) {
                        return preg_match('/'.$part.'\..+$/', $field);
                    }
                );

                // Get all given sorts related to the current part
                $withHistory[$currentHistoryPath]['sorts'] = array_filter(
                    $this->additionalSorts,
                    function ($pair) use ($part) {
                        return preg_match('/'.$part.'\..+$/', $pair[0]);
                    }
                );

                if (! isset($previousModel)) {
                    $previousModel = $baseModel;
                }

                // Throw a new ApiHandlerException if the relation doesn't exist
                // or is not properly marked as a relation
                if (! $this->isRelation($previousModel, $part)) {
                    throw new ApiHandlerException('UnknownResourceRelation', ['relation' => $part]);
                }

                $relation = call_user_func([$previousModel, $part]);
                $relationType = $this->getRelationType($relation);

                if ($relationType === 'BelongsTo') {
                    $firstKey = $relation->getQualifiedForeignKeyName();
                    $secondKey = $relation->getQualifiedParentKeyName();
                } elseif ($relationType === 'HasMany' || $relationType === 'HasOne') {
                    $firstKey = $relation->getQualifiedParentKeyName();
                    if (method_exists($relation, 'getQualifiedForeignKeyName')) {
                        $secondKey = $relation->getQualifiedForeignKeyName();
                    } else {
                        // compatibility for laravel < 5.4
                        $secondKey = $relation->getForeignKey();
                    }
                } elseif ($relationType === 'BelongsToMany') {
                    $firstKey = $relation->getQualifiedParentKeyName();
                    $secondKey = $relation->getRelated()->getQualifiedKeyName();
                } elseif ($relationType === 'HasManyThrough') {
                    if (method_exists($relation, 'getQualifiedLocalKeyName')) {
                        $firstKey = $relation->getQualifiedLocalKeyName();
                    } elseif (method_exists($relation, 'getExistenceCompareKey')) {
                        // compatibility for laravel 5.4
                        $firstKey = $relation->getExistenceCompareKey();
                    } else {
                        // compatibility for laravel < 5.4
                        $firstKey = $relation->getHasCompareKey();
                    }
                    $secondKey = null;
                } elseif ($relationType === 'MorphOne') {
                    continue;
                } else {
                    exit("{$relationType} Relation type not supported!");
                }

                // Check if we're on level 1 (e.g. a and not a.b)
                if ($firstKey !== null && $previousHistoryPath == '') {
                    if ($fieldsCount > 0 && ! in_array($firstKey, $fields)) {
                        $fields[] = $firstKey;
                    }
                } elseif ($firstKey !== null) {
                    if (
                        count($withHistory[$previousHistoryPath]['fields']) > 0
                        && ! in_array($firstKey, $withHistory[$previousHistoryPath]['fields'])
                    ) {
                        $withHistory[$previousHistoryPath]['fields'][] = $firstKey;
                    }
                }

                if (
                    $secondKey !== null && count($withHistory[$currentHistoryPath]['fields']) > 0 &&
                    ! in_array($secondKey, $withHistory[$currentHistoryPath]['fields'])
                ) {
                    $withHistory[$currentHistoryPath]['fields'][] = $secondKey;
                }

                $previousModel = $relation->getModel();
            }

            unset($previousModel);
        }

        // Apply the withHistory to using the laravel "with" function
        $withsArr = [];

        foreach ($withHistory as $withHistoryKey => $withHistoryValue) {
            $withsArr[$withHistoryKey] = function ($query) use ($withHistory, $withHistoryKey) {
                // Reduce field values to fieldname
                $fields = array_map(function ($field) {
                    $pos = strpos($field, '.');

                    return $pos !== false ? substr($field, $pos + 1) : $field;
                }, $withHistory[$withHistoryKey]['fields']);

                if (count($fields) > 0 && is_array($fields)) {
                    $query->select($fields);
                }

                // Attach sorts
                foreach ($withHistory[$withHistoryKey]['sorts'] as $pair) {
                    call_user_func_array([$query, 'orderBy'], $pair);
                }
            };
        }

        if ($count) {
            $this->builder->withCount($withsArr);
        } else {
            $this->builder->with($withsArr);
        }

        // Merge the base fields
        if (count($fields ?: []) > 0) {
            if (! is_array($this->query->columns)) {
                $this->query->columns = [];
            }

            $this->query->columns = array_merge($this->query->columns, $fields);
        }
    }
}
