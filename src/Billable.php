<?php

namespace Bgultekin\CashierFastspring;

use Bgultekin\CashierFastspring\Exceptions\NotImplementedException;
use Bgultekin\CashierFastspring\Fastspring\Fastspring;
use Exception;

trait Billable
{
    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param int   $amount
     * @param array $options
     *
     * @throws \InvalidArgumentException
     * @throws Exceptions\NotImplementedException
     */
    public function charge($amount, array $options = [])
    {
        throw new NotImplementedException();
    }

    /**
     * Refund a customer for a charge.
     *
     * @param string $charge
     * @param array  $options
     *
     * @throws \InvalidArgumentException
     * @throws Exceptions\NotImplementedException
     */
    public function refund($charge, array $options = [])
    {
        throw new NotImplementedException();
    }

    /**
     * Begin creating a new subscription.
     *
     * @param string $subscription
     * @param string $plan
     *
     * @return \Bgultekin\CashierFastspring\SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the subscription is on trial.
     *
     * @param string      $subscription
     * @param string|null $plan
     *
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
               $subscription->plan === $plan;
    }

    /**
     * Determine if the Fastspring model is on a "generic" trial at the model level.
     *
     * @param null $plan
     * @return bool
     */
    public function onGenericTrial($plan = null)
    {
        if (is_null($plan)) {
            return $this->trial_ends_at && $this->trial_ends_at->isFuture();
        }

        return $this->trial_ends_at && $this->trial_ends_at->isFuture() && $this->trial_plan === $plan;
    }

    /**
     * @param $plan
     *
     * @return void
     */
    public function swapTrialPlan($plan)
    {
        $this->trial_plan = $plan;

        $this->save();
    }

    /**
     * Determine if the model has a given subscription.
     *
     * @param string      $subscription
     * @param string|null $plan
     *
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        if ($this->onGenericTrial($plan)) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
               $subscription->plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param string $subscription
     *
     * @return \Bgultekin\CashierFastspring\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->subscriptions()
            ->where('name', $subscription)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Get all of the subscriptions for the model.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, $this->getForeignKey())->orderBy('created_at', 'desc');
    }

    /**
     * Get all of the FastSpring invoices for the current user.
     *
     * @return object
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class, $this->getForeignKey())->orderBy('created_at', 'desc');
    }

    /**
     * Determine if the model is actively subscribed to one of the given plans.
     *
     * @param array|string $plans
     * @param string       $subscription
     *
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default')
    {
        if ($this->onGenericTrial()) {
            foreach ((array) $plans as $plan) {
                if ($this->onGenericTrial($plan)) {
                    return true;
                }
            }
        }

        $subscription = $this->subscription($subscription);

        if (!$subscription || !$subscription->valid()) {
            return false;
        }

        foreach ((array) $plans as $plan) {
            if ($subscription->plan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param string $plan
     *
     * @return bool
     */
    public function onPlan($plan)
    {
        if ($this->onGenericTrial($plan)) {
            return true;
        }

        return !is_null($this->subscriptions->first(function ($value) use ($plan) {
            return $value->plan === $plan && $value->valid();
        }));
    }

    /**
     * Determine if the entity has a Fastspring customer ID.
     *
     * @return bool
     */
    public function hasFastspringId()
    {
        return !is_null($this->fastspring_id);
    }

    /**
     * Generate authenticated url of fastspring account management panel.
     *
     * @return bool
     */
    public function accountManagementURI()
    {
        $response = Fastspring::getAccountManagementURI($this->fastspring_id);

        return $response->accounts[0]->url;
    }

    /**
     * Create a Fastspring customer for the given user model.
     *
     * @param array $options
     *
     * @return object
     */
    public function createAsFastspringCustomer(array $options = [])
    {
        $options = empty($options) ? [
            'contact' => [
                'first'   => $this->first_name,
                'last'    => $this->last_name,
                'email'   => $this->email,
                'company' => $this->fastspring_company,
                'phone'   => $this->phone,
            ],
            'language' => $this->language,
            'country'  => $this->country,
        ] : $options;

        // Here we will create the customer instance on Fastspring and store the ID of the
        // user from Fastspring. This ID will correspond with the Fastspring user instances
        // and allow us to retrieve users from Fastspring later when we need to work.
        $account = Fastspring::createAccount($options);

        $this->fastspring_id = $account->account;

        $this->save();

        return $account;
    }

    /**
     * Update the related account on the Fastspring-side the given user model.
     *
     * @param array $options
     *
     * @return object
     */
    public function updateAsFastspringCustomer(array $options = [])
    {
        // check the fastspring_id first
        // if there is non, no need to try
        if (!$this->hasFastspringId()) {
            throw new Exception('User has no fastspring_id');
        }

        $options = empty($options) ? [
            'contact' => [
                'first'   => $this->first_name,
                'last'    => $this->last_name,
                'email'   => $this->email,
                'company' => $this->fastspring_company,
                'phone'   => $this->phone,
            ],
            'language' => $this->language,
            'country'  => $this->country,
        ] : $options;

        // update
        $response = Fastspring::updateAccount($this->fastspring_id, $options);

        return $response;
    }

    /**
     * Get the Fastspring customer for the model.
     *
     * @return object
     */
    public function asFastspringCustomer()
    {
        // check the fastspring_id first
        // if there is non, no need to try
        if (!$this->hasFastspringId()) {
            throw new Exception('User has no fastspring_id');
        }

        return Fastspring::getAccount($this->fastspring_id);
    }


    public function hasSubscription()
    {
        if($this->hasPiggybackSubscription()){
            return true;
        }
        return $this->subscribed();
    }

    public function hasTeamSubscription()
    {
        return optional($this->plan)->isForTeams();
    }

    public function hasPiggybackSubscription()
    {
        foreach ($this->teams as $team) {
            if ($team->owner->hasSubscription()) {
                return true;
            }
        }
        return false;
    }

    public function resetTeamMembers()
    {
        if ($this->team->users->count() > $this->subscription()->plan()->teams_limit) {
            $this->team->users()->delete();
        }
    }

}
