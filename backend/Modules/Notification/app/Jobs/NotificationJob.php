<?php

namespace Modules\Notification\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Notification\Models\Notification;

class NotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    protected $users;

    protected $notification;

    public function __construct(Notification $notification, $users = null)
    {
        $this->users = $users;
        $this->notification = $notification;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (is_null($this->users)) {
            User::chunk(100, function ($users) {
                NotificationUserJob::dispatch($this->notification, $users);
            });
        } else {
            $this->users->chunk(100, function ($users) {
                NotificationUserJob::dispatch($this->notification, $users);
            });
        }

        $this->notification->status = 'sent';
        $this->notification->save();
    }
}
