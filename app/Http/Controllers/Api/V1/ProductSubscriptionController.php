<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\ProductSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * The three add-on products, bought individually.
 *
 * One controller for all three, where the old API had three of 346-355 lines
 * each differing in a table name, a price key and one error message. The
 * product comes from the route, so each endpoint keeps the path the app
 * already calls.
 *
 * A year of each comes free with a property-access subscription, so these are
 * for buying one separately or renewing after that year.
 */
class ProductSubscriptionController extends Controller
{
    public function wmu(Request $request, ProductSubscriptions $products): JsonResponse
    {
        return $this->buy($request, $products, 'wmu');
    }

    public function darkMode(Request $request, ProductSubscriptions $products): JsonResponse
    {
        return $this->buy($request, $products, 'dark_mode');
    }

    public function fishingLands(Request $request, ProductSubscriptions $products): JsonResponse
    {
        return $this->buy($request, $products, 'fishing_lands');
    }

    private function buy(Request $request, ProductSubscriptions $products, string $product): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        if (! app(PaymentGateway::class)->isConfigured()) {
            return ApiResponse::error('Payments are not available on this server.', 503);
        }

        try {
            $result = $products->buy(
                user: $this->caller($request),
                product: $product,
                paymentMethodId: (string) $request->input('payment_method_id'),
            );
        } catch (PaymentGatewayException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::data($result, 201);
    }
}
