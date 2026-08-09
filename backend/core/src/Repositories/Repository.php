<?php

namespace QCH\Core\Repositories;

use Illuminate\Database\Eloquent\Model;
use QCH\Core\Models\BaseModel;

/**
 * Class Repository
 */
abstract class Repository
{
    /**
     * @var Model instance
     */
    public $model;

    /**
     * Handle dynamic static method calls into the method.
     *
     * @param  string  $method  Method
     * @param  array  $parameters  Parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return self::getModel()->$method(...$parameters);
    }

    /**
     * Handle dynamic method calls into the method.
     *
     * @param  string  $method  Method
     * @param  array  $parameters  Parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return self::getModel()->$method(...$parameters);
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return self::getModel()->getAttribute($key);
    }

    /**
     * Repository constructor.
     */
    public function __construct()
    {
        $this->makeModel();
    }

    /**
     * Query Base
     *
     * @return mixed
     */
    public function queryBase()
    {
        return $this->model;
    }

    /**
     * Query
     *
     * @return mixed
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Specify Model class name
     *
     * @return mixed
     */
    abstract public function model();

    /**
     * Make Model
     *
     * @return BaseModel
     *
     * @throws \Exception
     */
    public function makeModel()
    {
        $model = app($this->model());

        if (! $model instanceof BaseModel && ! $model instanceof Model) {
            throw new \Exception(
                "Class {$this->model()} must be an instance of Illuminate\\Database\\Eloquent\\Model"
            );
        }

        $this->model = $model;

        return $this->model;
    }
}
