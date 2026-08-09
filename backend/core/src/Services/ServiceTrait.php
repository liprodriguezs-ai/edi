<?php

namespace QCH\Core\Services;

/**
 * Class ServiceTrait
 */
trait ServiceTrait
{
    protected $repository;

    /**
     * RewardService constructor.
     */
    public function __construct() {}

    /**
     * @return mixed
     */
    public function store($request)
    {
        return $this->repository->create(
            $request
        );
    }

    /**
     * @return mixed
     */
    public function update($request, $id)
    {
        return $this->repository->update($request, $id);
    }

    /**
     * @return mixed
     */
    public function destroy($id)
    {
        return $this->repository->delete($id);
    }

    /**
     * @param  array  $columns
     * @return mixed
     */
    public function query($columns = ['*'])
    {
        return $this->repository->paginate($columns);
    }

    /**
     * @param  array  $columns
     * @return mixed
     */
    public function all($columns = ['*'])
    {
        return $this->repository->all($columns);
    }

    /**
     * @param  array  $columns
     * @return mixed
     */
    public function show($id, $columns = ['*'])
    {
        return $this->model->find($id, $columns);
    }
}
