<?php

namespace QCH\Core\Models;

trait Subscription
{
    public function getSubscriptionValidAttribute()
    {
        $checkSubscription = $this->checkSubscription();
        $this->subscription_valid = $checkSubscription;

        return $checkSubscription;
    }

    public function checkSubscription(): bool
    {
        $user = auth()->user();
        if ($this->plans->count() === 0) {
            return true;
        }
        $subscribed = $user->subscribed(config('services.stripe.plan_default'));
        if (! $subscribed) {
            return false;
        }
        $subscription = $user->subscription(config('services.stripe.plan_default'));
        $valids = $this->plans()->where(['stripe_id' => $subscription->stripe_price])->get();
        if (count($valids) > 0) {
            return true;
        }

        return false;
    }
}
