<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Skip unless a Stripe *sandbox* key is configured.
     *
     * For the tests that call Stripe for real. Two prefixes, not one: Stripe
     * has merged standard and restricted keys into a single kind, so a newer
     * account issues `rk_test_` where an older one issued `sk_test_`, for the
     * same thing. Checking only `sk_test_` is how these tests came to skip
     * silently the day the account changed — passing suite, no Stripe
     * coverage, nothing to see in the output.
     *
     * Deliberately hardcoded to `_test_` rather than read from
     * `services.stripe.allowed_key_prefixes`: that list can be widened to a
     * live prefix for a real deployment, and these tests create customers and
     * charge cards. They must never run against a live key, whatever the
     * configuration says.
     */
    protected function requireStripeSandbox(): void
    {
        $secret = (string) config('services.stripe.secret');

        if (preg_match('/^(sk|rk)_test_/', $secret) !== 1) {
            $this->markTestSkipped('No Stripe sandbox key configured; see .env.example.');
        }
    }
}
