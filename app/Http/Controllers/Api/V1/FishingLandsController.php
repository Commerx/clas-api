<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sport fishing regulations: one PDF per province.
 *
 * Reference data, thirteen rows, no account involvement. The `region` column
 * exists for a province that needs more than one document and is null in
 * every row, so neither endpoint uses it.
 *
 * The old pair returned different shapes under the same key — `regulations`
 * was a list from one endpoint and a single object from the other. Here the
 * list is `items` and the single row is `item`, which is what the rest of
 * this API uses, so the app can tell them apart by name.
 */
class FishingLandsController extends Controller
{
    private const COLUMNS = ['id', 'province', 'region', 'regulations_pdf', 'created'];

    /** Every province's regulations. */
    public function index(): JsonResponse
    {
        $regulations = DB::table('fishing_lands_pdfs')
            ->orderBy('province')
            ->get(self::COLUMNS)
            ->map(fn ($row) => ['id' => (int) $row->id] + (array) $row)
            ->all();

        return ApiResponse::data(['items' => $regulations]);
    }

    /**
     * One province's regulations.
     *
     * The old endpoint defaulted the province to AB when none was given,
     * while also marking it required, so the default was unreachable. It is
     * required here and nothing is assumed.
     */
    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'province' => ['required', 'string', 'max:10'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $regulations = DB::table('fishing_lands_pdfs')
            ->where('province', (string) $request->input('province'))
            ->first(self::COLUMNS);

        if ($regulations === null) {
            return ApiResponse::notFound('No regulations for that province.');
        }

        return ApiResponse::data([
            'item' => ['id' => (int) $regulations->id] + (array) $regulations,
        ]);
    }
}
