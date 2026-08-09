<?php

namespace QCH\Core\Repositories;

/**
 * Interface RepositoryInterface
 */
interface RepositoryInterface
{
    public function all($columns = ['*']);

    public function create(array $data);

    public function update(array $data, $id);

    public function delete($id);

    public function find($id, $columns = ['*']);
}
