<?php

namespace Modules\User\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;

class UserAddPointsJob extends UserPoints implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $order;

    public $metadata;

    public function __construct(Order $order, $metadata = [])
    {
        $this->order = $order;
        $this->metadata = $metadata;
    }

    public function handle()
    {

        $user = $this->order?->user;

        if ($user) {
            Log::debug('UserAddPointsOrderJob', ['user' => $user]);
            // $this->addPoints($this->order, $this->order->points, $this->metadata);
        }
    }
}
