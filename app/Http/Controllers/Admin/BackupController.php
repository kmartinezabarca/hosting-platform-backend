<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BackupController extends Controller
{
    public function __construct(private BackupService $backups)
    {
    }

    /* ───────────────── Backups ───────────────── */

    /** GET /admin/backups */
    public function index(Request $request): JsonResponse
    {
        $data = $this->backups->list($request->only([
            'type', 'status', 'user_id', 'service_id', 'search', 'per_page',
        ]));

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /admin/backups/stats */
    public function stats(): JsonResponse
    {
        $disk = config('backup.disk', 'nas');

        return response()->json([
            'success' => true,
            'data'    => [
                'total'        => Backup::count(),
                'completed'    => Backup::where('status', 'completed')->count(),
                'failed'       => Backup::where('status', 'failed')->count(),
                'running'      => Backup::where('status', 'running')->count(),
                'total_size'   => (int) Backup::sum('size_bytes'),
                'by_type'      => Backup::selectRaw('type, COUNT(*) c')
                                        ->groupBy('type')->pluck('c', 'type'),
                'schedules'    => BackupSchedule::where('is_enabled', true)->count(),
                'nas_disk'     => $disk,
                'nas_reachable'=> $this->nasReachable($disk),
            ],
        ]);
    }

    /** POST /admin/backups */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'        => 'required|in:platform,game_server,hosting,client_files',
            'name'        => 'nullable|string|max:160',
            'user_id'     => 'nullable|exists:users,id',
            'service_id'  => 'nullable|exists:services,id',
            'source_path' => 'nullable|string|max:1024',
        ]);

        $backup = $this->backups->create($validated['type'], $validated);

        return response()->json([
            'success' => $backup->status !== 'failed',
            'data'    => $backup->load('user:id,first_name,last_name', 'service:id,name'),
            'message' => $backup->status === 'failed'
                ? ($backup->error ?: 'El respaldo falló.')
                : 'Respaldo creado.',
        ], $backup->status === 'failed' ? 422 : 201);
    }

    /** DELETE /admin/backups/{backup} */
    public function destroy(Backup $backup): JsonResponse
    {
        $this->backups->delete($backup);
        return response()->json(['success' => true, 'message' => 'Respaldo eliminado.']);
    }

    /** POST /admin/backups/bulk-delete */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uuids'   => 'required|array|min:1',
            'uuids.*' => 'string',
        ]);

        $deleted = $this->backups->bulkDelete($validated['uuids']);

        return response()->json([
            'success' => true,
            'message' => "{$deleted} respaldo(s) eliminado(s).",
            'deleted' => $deleted,
        ]);
    }

    /** GET /admin/backups/{backup}/download */
    public function download(Backup $backup)
    {
        if (!$backup->path || !Storage::disk($backup->disk)->exists($backup->path)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo de respaldo no está disponible en el NAS.',
            ], 404);
        }

        return Storage::disk($backup->disk)->download(
            $backup->path,
            $backup->name . '.zip'
        );
    }

    /** POST /admin/backups/scan-nas */
    public function scanNas(): JsonResponse
    {
        try {
            $result = $this->backups->scanNas();
            return response()->json([
                'success' => true,
                'message' => "{$result['registered']} archivo(s) registrado(s), {$result['skipped']} omitido(s).",
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /* ───────────────── Programaciones ───────────────── */

    /** GET /admin/backups/schedules */
    public function schedules(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => BackupSchedule::orderByDesc('created_at')->get(),
        ]);
    }

    /** POST /admin/backups/schedules */
    public function storeSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:160',
            'type'            => 'required|in:platform,game_server,hosting,client_files',
            'scope'           => 'required|in:all,user,service',
            'scope_id'        => 'nullable|integer',
            'frequency'       => 'required|in:daily,weekly,monthly,cron',
            'cron_expression' => 'nullable|string|max:120',
            'run_at_time'     => 'nullable|string|max:5',
            'run_at_day'      => 'nullable|integer|min:0|max:31',
            'retention_days'  => 'nullable|integer|min:1|max:3650',
            'is_enabled'      => 'boolean',
        ]);

        $schedule = new BackupSchedule($validated);
        $schedule->retention_days = $validated['retention_days'] ?? config('backup.retention_days', 30);
        $schedule->is_enabled = $validated['is_enabled'] ?? true;
        $schedule->next_run_at = $schedule->computeNextRun();
        $schedule->save();

        return response()->json(['success' => true, 'data' => $schedule], 201);
    }

    /** PUT /admin/backups/schedules/{schedule} */
    public function updateSchedule(Request $request, BackupSchedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'sometimes|string|max:160',
            'frequency'       => 'sometimes|in:daily,weekly,monthly,cron',
            'cron_expression' => 'nullable|string|max:120',
            'run_at_time'     => 'nullable|string|max:5',
            'run_at_day'      => 'nullable|integer|min:0|max:31',
            'retention_days'  => 'nullable|integer|min:1|max:3650',
            'is_enabled'      => 'sometimes|boolean',
        ]);

        $schedule->fill($validated);
        $schedule->next_run_at = $schedule->computeNextRun();
        $schedule->save();

        return response()->json(['success' => true, 'data' => $schedule]);
    }

    /** DELETE /admin/backups/schedules/{schedule} */
    public function destroySchedule(BackupSchedule $schedule): JsonResponse
    {
        $schedule->delete();
        return response()->json(['success' => true, 'message' => 'Programación eliminada.']);
    }

    /** POST /admin/backups/schedules/{schedule}/run */
    public function runSchedule(BackupSchedule $schedule): JsonResponse
    {
        $backup = $this->backups->create($schedule->type, [
            'name'        => $schedule->name . ' (manual)',
            'schedule_id' => $schedule->id,
            'user_id'     => $schedule->scope === 'user' ? $schedule->scope_id : null,
            'service_id'  => $schedule->scope === 'service' ? $schedule->scope_id : null,
        ]);

        return response()->json([
            'success' => $backup->status !== 'failed',
            'data'    => $backup,
            'message' => $backup->status === 'failed'
                ? ($backup->error ?: 'El respaldo falló.')
                : 'Respaldo ejecutado.',
        ], $backup->status === 'failed' ? 422 : 200);
    }

    private function nasReachable(string $disk): bool
    {
        try {
            Storage::disk($disk)->files(config('backup.root', 'backups'));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
