<?php

namespace QCH\Core\Http\Api;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;
use Marcelgwerder\ApiHandler\Facades\ApiHandler as ApiHandlerBase;

/**
 * @method void paginate($text)
 */
class ApiHandler extends ApiHandlerBase
{
    /**
     * Return a new Result object for a single dataset.
     *
     * @param  mixed  $queryBuilder  Some kind of query builder instance
     * @param  array|int  $identification  Identification of the dataset to work with
     * @param  array|bool  $queryParams  The parameters used for parsing
     * @return Marcelgwerder\ApiHandler\Result Result object that provides getter methods
     */
    public static function parseSingle($queryBuilder, $identification, $queryParams = false)
    {
        if ($queryParams === false) {
            $queryParams = request()->query();
        }

        $parser = new Parser($queryBuilder, $queryParams);
        $parser->parse($identification);

        return new Result($parser);
    }

    /**
     * Return a new Result object for multiple datasets.
     *
     * @param  mixed  $queryBuilder  Some kind of query builder instance
     * @param  array  $fullTextSearchColumns  Columns to search in fulltext search
     * @param  array|bool  $queryParams  A list of query parameter
     * @return Result
     */
    public static function parseMultiple($queryBuilder, $fullTextSearchColumns = [], $queryParams = false)
    {
        if ($queryParams === false) {
            $queryParams = request()->query();
        }

        if (count($fullTextSearchColumns) == 0 && request()->has('_fullTextSearchColumns')) {
            $fullTextSearchColumns = request()->input('_fullTextSearchColumns');
        } elseif (count($fullTextSearchColumns) == 0 && ! request()->input('_fullTextSearchColumns')) {
            $fullTextSearchColumns = Config::get('apihandler.fullTextSearchColumns');
        }

        $parser = new Parser($queryBuilder, $queryParams);
        $parser->parse($fullTextSearchColumns, true);

        return new Result($parser);
    }

    /**
     * Return a new "created" response object.
     *
     * @param  array|object  $object
     * @return Response
     */
    public function created($object)
    {
        return Response::json($object, 201);
    }

    /**
     * Return a new "updated" response object.
     *
     * @param  array|object  $object
     * @return Response
     */
    public function updated($object = null)
    {
        if ($object != null) {
            return Response::json($object, 200);
        } else {
            return Response::make(null, 204);
        }
    }

    /**
     * Return a new "deleted" response object.
     *
     * @param  array|object  $object
     * @return Response
     */
    public function deleted($object = null)
    {
        if ($object != null) {
            return Response::json($object, 200);
        } else {
            return Response::make(null, 204);
        }
    }
}
