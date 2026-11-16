<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\AttendanceSync;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSync as SyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

/** Endpoints for the attendance bridge (routes/api.php). */
class AttendanceSyncController extends Controller
{
    /** Max size of the decompressed JSON body. */
    public const MAX_BYTES = 30 * 1024 * 1024;

    /** POST /api/attendance-sync — one month; replaces the stored month. */
    public function store(Request $request, AttendanceSync $sync): JsonResponse
    {
        @set_time_limit(300);

        $raw = $request->getContent();
        if (str_contains(strtolower((string) $request->header('Content-Encoding')), 'gzip')) {
            $raw = @gzdecode($raw, self::MAX_BYTES);
            if ($raw === false) {
                return response()->json(['error' => 'Body is not valid gzip or is too large.'], 400);
            }
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return response()->json(['error' => 'Body too large.'], 413);
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $result = $sync->ingest(is_array($payload) ? $payload : [], $request->ip());
        } catch (JsonException) {
            return response()->json(['error' => 'Body is not valid JSON.'], 400);
        } catch (InvalidArgumentException $e) {
            SyncLog::query()->create(['kind' => 'data', 'month' => is_array($payload ?? null) ? mb_substr((string) ($payload['month'] ?? ''), 0, 7) ?: null : null,
                'ok' => false, 'message' => $e->getMessage(), 'ip' => $request->ip(), 'received_at' => now()]);

            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $result);
    }

    /** GET /api/attendance-sync/pending — did an admin press „Synchronizovať teraz“? */
    public function pending(AttendanceSync $sync): JsonResponse
    {
        return response()->json($sync->pending());
    }

    /** POST /api/attendance-sync/status — the bridge reports success/failure (shown in the admin). */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        SyncLog::query()->create(['kind' => 'status', 'ok' => $data['ok'], 'message' => $data['message'] ?? null,
            'ip' => $request->ip(), 'received_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
