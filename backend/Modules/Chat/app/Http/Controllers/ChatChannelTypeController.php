<?php

namespace Modules\Chat\Http\Controllers;

use Modules\Chat\Models\ChatChannelType;
use QCH\Core\Http\Controllers\Controller;

class ChatChannelTypeController extends Controller
{
    protected $model = ChatChannelType::class;

    protected $keyForPermission = 'chat_channel_type';

    public function sortableBy(): array
    {
        return ['name', 'order'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }
}
