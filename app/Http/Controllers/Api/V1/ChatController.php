<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Saved conversations with the in-app assistant.
 *
 * The app holds the conversation and posts the whole of it back, so this is
 * storage: a title, a blob of history, and the account it belongs to. Nothing
 * here talks to a model.
 *
 * Access control was already right in the old controller — every lookup was
 * `findOne(['id' => $chat_id, 'user_id' => $uid])` — so the change here is
 * the envelope, the status codes, and limits on what may be stored. The old
 * endpoints took the account from `uid` rather than the token; this one takes
 * it from the token, like every other endpoint in this API.
 */
class ChatController extends Controller
{
    /**
     * How much history one conversation may hold.
     *
     * The column is LONGTEXT and the old endpoints had no limit at all, so a
     * client could store as much as it could post. A megabyte is far more
     * than a conversation needs and small enough that the table cannot be
     * used as free storage.
     */
    private const MAX_HISTORY = 1_000_000;

    /** The caller's conversations, most recently touched first. */
    public function index(Request $request): JsonResponse
    {
        $chats = DB::table('chat_history')
            ->where('user_id', $this->userId($request))
            ->orderByDesc('updated')
            ->orderByDesc('created')
            ->get(['id', 'chat_title', 'created', 'updated'])
            ->map(fn ($chat) => ['id' => (int) $chat->id] + (array) $chat)
            ->all();

        return ApiResponse::data(['items' => $chats]);
    }

    /**
     * One conversation, with its history.
     *
     * `history` is returned decoded, as the old endpoint returned it. What
     * comes back therefore mirrors what was sent: see store() for why that is
     * not always an object.
     */
    public function show(Request $request): JsonResponse
    {
        $chat = $this->ownedChat($request);

        if ($chat instanceof JsonResponse) {
            return $chat;
        }

        return ApiResponse::data([
            'id' => (int) $chat->id,
            'chat_title' => $chat->chat_title,
            'history' => json_decode((string) $chat->history),
            'created' => $chat->created,
            'updated' => $chat->updated,
        ]);
    }

    /**
     * Start a conversation.
     *
     * `history` is stored as `json_encode($history)`, exactly as before, so
     * the round trip through show() is unchanged for the app. Worth knowing
     * that this means the stored shape follows the request: history posted as
     * a JSON string is stored as a JSON string and read back as a string,
     * while history posted as a form array is stored as an object. The old
     * API behaved the same way, and matching it keeps existing rows readable.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'question' => ['required', 'string', 'max:1000'],
            'history' => ['required'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $history = $this->encodeHistory($request->input('history'));

        if ($history === null) {
            return ApiResponse::invalid([
                'history' => ['That history is too large to store.'],
            ]);
        }

        $id = (int) DB::table('chat_history')->insertGetId([
            'user_id' => $this->userId($request),
            'chat_title' => (string) $request->input('question'),
            'history' => $history,
        ]);

        return ApiResponse::data(['id' => $id], 201);
    }

    /** Replace the history of one of the caller's conversations. */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'chat_id' => ['required', 'integer'],
            'history' => ['required'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $chat = $this->ownedChat($request);

        if ($chat instanceof JsonResponse) {
            return $chat;
        }

        $history = $this->encodeHistory($request->input('history'));

        if ($history === null) {
            return ApiResponse::invalid([
                'history' => ['That history is too large to store.'],
            ]);
        }

        // `updated` is set here rather than left to the column, which has no
        // ON UPDATE clause — the old code relied on the model's timestamp
        // behaviour, and list() orders by it.
        DB::table('chat_history')->where('id', $chat->id)->update([
            'history' => $history,
            'updated' => now(),
        ]);

        return ApiResponse::data(['id' => (int) $chat->id]);
    }

    /** Delete one of the caller's conversations. */
    public function destroy(Request $request): JsonResponse
    {
        $chat = $this->ownedChat($request);

        if ($chat instanceof JsonResponse) {
            return $chat;
        }

        DB::table('chat_history')->where('id', $chat->id)->delete();

        return ApiResponse::data(['deleted' => true]);
    }

    /**
     * The conversation named by `chat_id`, if it is the caller's.
     *
     * Somebody else's conversation and one that does not exist both answer
     * 404, so the response does not confirm that an id is in use.
     *
     * Returns the row, or the response to send instead. The hint is `object`
     * rather than `object|JsonResponse` because a JsonResponse is an object
     * and PHP rejects the union as redundant.
     */
    private function ownedChat(Request $request): object
    {
        $validator = Validator::make($request->all(), [
            'chat_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $chat = DB::table('chat_history')
            ->where('id', (int) $request->input('chat_id'))
            ->where('user_id', $this->userId($request))
            ->first(['id', 'chat_title', 'history', 'created', 'updated']);

        return $chat ?? ApiResponse::notFound('No such conversation.');
    }

    /** The history as it will be stored, or null if it is too large. */
    private function encodeHistory(mixed $history): ?string
    {
        $encoded = json_encode($history);

        if ($encoded === false || strlen($encoded) > self::MAX_HISTORY) {
            return null;
        }

        return $encoded;
    }
}
