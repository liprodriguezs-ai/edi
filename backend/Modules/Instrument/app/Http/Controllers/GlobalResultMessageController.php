<?php

namespace Modules\Instrument\Http\Controllers;

use Modules\Instrument\Models\GlobalResultMessage;
use QCH\Core\Http\Controllers\Controller;

class GlobalResultMessageController extends Controller
{
    protected $model = GlobalResultMessage::class;

    protected $keyForPermission = 'instrument';

    public function includes(): array
    {
        return ['result_color'];
    }

    public function sortableBy(): array
    {
        return ['sort_order'];
    }

    public function filterableBy(): array
    {
        return ['instrument_version_id'];
    }
}
