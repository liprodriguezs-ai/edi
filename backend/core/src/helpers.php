<?php

use Illuminate\Support\Facades\Log;

function nl2p($string, $line_breaks = false, $xml = true)
{
    // remove existing HTML formatting to avoid double tags
    $string = str_replace(['<p>', '</p>', '<br>', '<br/>'], '', $string);

    // convert single line breaks into <br> or <br/> tags
    if ($line_breaks == true) {
        return '<p>'.preg_replace(["/\r/", '/{2,}/', '//'], ['', '</p><p>', '<br'.($xml == true ? '/' : '').'>'], $string).'</p>';
    } else {
        return '<p>'.preg_replace('//', '</p><p>', trim($string)).'</p>';
    }
}

function createTriggerIncrement($name)
{
    return <<<EOD
CREATE TRIGGER {$name}_increment BEFORE INSERT
ON {$name}
FOR EACH ROW
BEGIN
   SET  NEW.`increment` = COALESCE((SELECT MAX(COALESCE(increment, 0)) + 1 FROM {$name}), 1);
END
EOD;
}

function fillProfilePermissions($roleID, $modules)
{
    $permisionAll = DB::table('permissions')->select(
        'id AS permission_id',
        DB::raw(" '$roleID' as role_id")
    )->whereIn('module_id', $modules)
        ->get()->map(function ($x) {
            return (array) $x;
        })->toArray();

    DB::table('permission_role')->insert((array) $permisionAll);
}

function removeProfilePermissions($roleID, $permisionSlug)
{
    DB::table('permission_role')->where('role_id', $roleID)
        ->whereIn('permission_id', function ($query) use ($permisionSlug) {
            $query->select('id')->from('permissions')->where('slug', $permisionSlug);
        })->delete();
}

function fillProfilePermission($roleID, $modules, $slug)
{
    $permisionAll = DB::table('permissions')->select(
        'id AS permission_id',
        DB::raw(" '$roleID' as role_id")
    )->where('slug', $slug)->whereIn('module_id', $modules)
        ->get()->map(function ($x) {
            return (array) $x;
        })->toArray();

    DB::table('permission_role')->insert((array) $permisionAll);
}

function getConfigStripe()
{
    return [
        'api_key' => config('services.stripe.secret'),
        'stripe_version' => '2020-08-27',
    ];
}

function subscriptionIsActive($entity)
{
    $user = auth()->user();
    if (! count($user->subscribed())) {
        return false;
    }

    return true;
}

function getPaymentPlan($entity)
{
    return $entity?->payment_plan ?? $entity?->parent?->payment_plan;
}

function getChannelLog($name)
{
    return Log::build([
        'driver' => 'daily',
        'path' => storage_path('logs/'.$name.'.log'),
    ]);
}
