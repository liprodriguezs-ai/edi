<?php

namespace QCH\Core\Services;

use League\Fractal;

class TransformerBase extends Fractal\TransformerAbstract
{
    public function transform($data)
    {
        return $data->toArray();
    }
}
