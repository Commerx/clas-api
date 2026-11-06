<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Payments\StripeWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stripe's callbacks.
 *
 * The one endpoint in this API with no token, because Stripe has no way to
 * present one. The signature in the `Stripe-Signature` header is the
 * authentication, and `StripeWebhooks` refuses everything when no signing
 * secret is configured rather than accepting unsigned payloads.
 *
 * It is named in `ResponseHygieneTest::PUBLIC_ROUTES`, which is what stops a
 * route being unauthenticated by accident: that test fails for any route
 * outside the guarded group that is not listed there deliberately.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhooks $webhooks): JsonResponse
    {
        /*
         * The raw body, not the parsed input.
         *
         * Stripe signs the exact bytes it sent. Re-encoding a parsed array
         * produces different bytes — key order, numeric formatting,
         * whitespace — and the signature would never verify.
         */
        $result = $webhooks->handle(
            payload: $request->getContent(),
            signature: (string) $request->header('Stripe-Signature', ''),
        );

        if ($result['status'] === 200) {
            return ApiResponse::data(['received' => true, 'outcome' => $result['outcome']]);
        }

        return ApiResponse::error($result['outcome'], $result['status']);
    }
}
