<?php

namespace QCH\Core\Services;

/**
 * Interface ServiceInterface
 */
interface ServiceInterface
{
    /**
     * Create new resource
     *
     *
     * @return mixed
     */
    public function store($request);

    /**
     * Update the specified resource in storage.
     *
     *
     * @return mixed
     */
    public function update($request, $id);

    /**
     * Remove the specified resource from storage.
     *
     * @category    Controller
     *
     * @param  int  $id
     */
    public function delete($id);

    /**
     * Display a listing of the resource.
     */
    public function query($request);

    /**
     * Display the specified resource.
     *
     * @category    Controller
     *
     * @param  int  $id
     */
    public function show($id);
}
