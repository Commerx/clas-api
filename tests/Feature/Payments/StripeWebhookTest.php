<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The webhook endpoint.
 *
 * Every event here is built and signed locally with a test secret, so nothing
 * contacts Stripe. That is deliberate: the properties worth testing are the
 * refusals and the idempotency, and those have to be exercised with payloads
 * Stripe would never send.
 */
class StripeWebhookTest extends TestCase
{
    private const SECRET = 'whsec_testsecretfortestsonly';

    private array $createdEventIds = [];

    private ?int $subscriptionId = null;

    private ?object $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
            'services.stripe.webhook_secret' => self::SECRET,
        ]);

        try {
            DB::connection('clas_api')->table('stripe_events')->count();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas_api not reachable, or the migration has not run: '
                .$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdEventIds !== []) {
            DB::connection('clas_api')->table('stripe_events')
                ->whereIn('id', $this->createdEventIds)->delete();
        }

        if ($this->subscriptionId !== null && $this->user !== null) {
            DB::table('subscription_transactions')
                ->where('subscription_id', $this->subscriptionId)->delete();
            DB::table('user_subscription')
                ->where('subscription_id', $this->subscriptionId)->delete();

            foreach (['wmu_subscription', 'fishing_lands_subscription'] as $table) {
                DB::table($table)->where('user_id', $this->user->id)->delete();
            }
        }

        parent::tearDown();
    }

    /** An event body, signed the way Stripe signs one. */
    private function send(array $event, ?string $secret = self::SECRET, int $age = 0): TestResponse
    {
        $this->createdEventIds[] = $event['id'];

        /*
         * Encoded without escaping slashes, which is how Stripe sends it:
         * their payloads carry values like "/v1/charges" literally.
         * PHP's json_encode escapes those to "\/v1\/charges" by default, so
         * anything that decodes the body and re-encodes it produces
         * different bytes and the signature cannot verify. That is why the
         * controller signs over $request->getContent() and not the parsed
         * input — and this is what makes the test prove it.
         */
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES);
        $timestamp = time() - $age;

        $header = 'v1=unsigned';

        if ($secret !== null) {
            $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
            $header = 't='.$timestamp.',v1='.$signature;
        }

        return $this->call(
            'POST', '/v1/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );
    }

    /** @param array<string,mixed> $object */
    private function event(string $type, array $object, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_test_'.bin2hex(random_bytes(8)),
            'object' => 'event',
            'api_version' => '2025-03-31.basil',
            'created' => time(),
            'type' => $type,
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            // A slash-bearing value, as Stripe's own payloads have.
            'url' => '/v1/webhook_endpoints',
            'data' => ['object' => $object],
        ];
    }

    // ---- the refusals ---------------------------------------------------

    /**
     * With no signing secret, nothing is processed.
     *
     * The signature is the only authentication this endpoint has, so an
     * unconfigured secret must mean "refuse", not "accept anything". 503
     * rather than 400 because Stripe retries a 5xx, so events that arrive
     * before the secret is set are redelivered rather than lost.
     */
    public function test_without_a_signing_secret_nothing_is_processed(): void
    {
        config(['services.stripe.webhook_secret' => '']);

        $event = $this->event('payment_intent.succeeded', ['id' => 'pi_x']);

        $this->send($event)
            ->assertStatus(503)
            ->assertJsonPath('error', 'no_signing_secret');

        $this->assertSame(0, DB::connection('clas_api')->table('stripe_events')
            ->where('id', $event['id'])->count(),
            'An event that could not be verified must not be recorded as received.');
    }

    public function test_a_bad_signature_is_rejected_and_not_retried(): void
    {
        $event = $this->event('payment_intent.succeeded', ['id' => 'pi_x']);

        // Signed with the wrong secret.
        $this->send($event, secret: 'whsec_thewrongsecret')
            ->assertStatus(400)
            ->assertJsonPath('error', 'bad_signature');

        $this->assertSame(0, DB::connection('clas_api')->table('stripe_events')
            ->where('id', $event['id'])->count());
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        $this->send($this->event('payment_intent.succeeded', ['id' => 'pi_x']), secret: null)
            ->assertStatus(400);
    }

    /** Stripe's own replay window: an old timestamp will not verify. */
    public function test_a_replayed_event_is_rejected(): void
    {
        $this->send(
            $this->event('payment_intent.succeeded', ['id' => 'pi_x']),
            age: 60 * 60,
        )->assertStatus(400);
    }

    // ---- idempotency ----------------------------------------------------

    /**
     * A redelivery is acknowledged without being acted on twice.
     *
     * Stripe retries until it gets a 2xx and may redeliver after a success,
     * so a handler that is not idempotent would revoke a subscription, or
     * refund-expire it, more than once.
     */
    public function test_a_redelivered_event_is_handled_once(): void
    {
        $event = $this->event('payment_intent.succeeded', ['id' => 'pi_x']);

        $this->send($event)->assertStatus(200)->assertJsonPath('data.outcome', 'noted');
        $this->send($event)->assertStatus(200)->assertJsonPath('data.outcome', 'duplicate');

        $this->assertSame(1, DB::connection('clas_api')->table('stripe_events')
            ->where('id', $event['id'])->count());
    }

    /** An event type this API does not act on is still acknowledged. */
    public function test_an_unhandled_event_type_is_acknowledged(): void
    {
        // A 4xx here would have Stripe retry forever and eventually disable
        // the endpoint for every event.
        $this->send($this->event('customer.updated', ['id' => 'cus_x']))
            ->assertStatus(200)
            ->assertJsonPath('data.outcome', 'ignored');
    }

    // ---- refunds --------------------------------------------------------

    /** A full refund revokes the subscription and its add-ons. */
    public function test_a_full_refund_revokes_the_subscription(): void
    {
        $this->givenASubscription('pi_refundtest');

        $this->send($this->event('charge.refunded', [
            'id' => 'ch_x',
            'payment_intent' => 'pi_refundtest',
            'refunded' => true,
            'amount' => 999,
            'amount_refunded' => 999,
        ]))->assertStatus(200)->assertJsonPath('data.outcome', 'subscription_revoked');

        $row = DB::table('user_subscription')->where('subscription_id', $this->subscriptionId)->first();

        $this->assertSame('Expired', $row->status);
        $this->assertSame(now()->format('Y-m-d'), $row->expiration_date);

        foreach (['wmu_subscription', 'fishing_lands_subscription'] as $table) {
            $this->assertSame('Expired', DB::table($table)
                ->where('user_id', $this->user->id)->value('status'),
                $table.' should be revoked with the subscription it came with.');
        }
    }

    /** A partial refund is recorded and changes nothing. */
    public function test_a_partial_refund_leaves_the_subscription_alone(): void
    {
        $this->givenASubscription('pi_partialtest');

        $before = (array) DB::table('user_subscription')
            ->where('subscription_id', $this->subscriptionId)->first();

        $this->send($this->event('charge.refunded', [
            'id' => 'ch_y',
            'payment_intent' => 'pi_partialtest',
            'refunded' => false,
            'amount' => 999,
            'amount_refunded' => 400,
        ]))->assertStatus(200)->assertJsonPath('data.outcome', 'partial_refund_noted');

        $this->assertSame($before, (array) DB::table('user_subscription')
            ->where('subscription_id', $this->subscriptionId)->first());
    }

    /** A refund for a charge this API does not know about is not fatal. */
    public function test_a_refund_with_no_matching_subscription_is_recorded(): void
    {
        $this->send($this->event('charge.refunded', [
            'id' => 'ch_z',
            'payment_intent' => 'pi_nothinghere',
            'refunded' => true,
        ]))->assertStatus(200)->assertJsonPath('data.outcome', 'refund_unmatched');
    }

    /** A refund must not reach across to a Moneris subscription. */
    public function test_a_refund_does_not_touch_a_moneris_subscription(): void
    {
        $this->givenASubscription('pi_monerisowned', gateway: 'moneris');

        $this->send($this->event('charge.refunded', [
            'id' => 'ch_m',
            'payment_intent' => 'pi_monerisowned',
            'refunded' => true,
        ]))->assertStatus(200)->assertJsonPath('data.outcome', 'refund_unmatched');

        $this->assertSame('Active', DB::table('user_subscription')
            ->where('subscription_id', $this->subscriptionId)->value('status'));
    }

    // ---- disputes -------------------------------------------------------

    /**
     * A dispute is logged and revokes nothing.
     *
     * It can be won, the response deadline is short, and the record of the
     * customer's use is often what wins it.
     */
    public function test_a_dispute_is_logged_without_revoking_access(): void
    {
        $this->givenASubscription('pi_disputetest');

        $this->send($this->event('charge.dispute.created', [
            'id' => 'dp_x',
            'charge' => 'ch_x',
            'amount' => 999,
            'reason' => 'fraudulent',
        ]))->assertStatus(200)->assertJsonPath('data.outcome', 'dispute_logged');

        $this->assertSame('Active', DB::table('user_subscription')
            ->where('subscription_id', $this->subscriptionId)->value('status'),
            'A dispute must not revoke access on its own.');
    }

    /** A subscription of the fixture account, charged against an intent. */
    private function givenASubscription(string $paymentIntent, string $gateway = 'stripe'): void
    {
        $user = DB::table('user')->where('username', 'contract_fixture')->first();

        if ($user === null) {
            $this->markTestSkipped('Fixture account missing.');
        }

        if (! DB::getSchemaBuilder()->hasColumn('user_subscription', 'gateway')) {
            $this->markTestSkipped('Run the gateway columns migration first.');
        }

        $this->user = $user;

        $this->subscriptionId = (int) DB::table('user_subscription')->insertGetId([
            'user_id' => $user->id,
            'subscription_type' => 5,
            'start_date' => now()->format('Y-m-d'),
            'expiration_date' => now()->addYear()->format('Y-m-d'),
            'auto_renew' => 0,
            'gateway' => $gateway,
            'status' => 'Active',
            'subscription_amount' => '9.99',
            'amount_paid' => '9.99',
            'txn_number' => $paymentIntent,
        ]);

        foreach (['wmu_subscription', 'fishing_lands_subscription'] as $table) {
            DB::table($table)->insert([
                'user_id' => $user->id,
                'start_date' => now()->format('Y-m-d'),
                'expiration_date' => now()->addYear()->format('Y-m-d'),
                'status' => 'Active',
                'subscription_amount' => '0.00',
                'gateway' => $gateway,
            ]);
        }
    }
}
