<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pet\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform-admin view of ROKE Pet notification logs.
 * Auth: platform admin (auth:sanctum + admin middleware) — NOT pet AppAdmin.
 */
class PetNotificationController extends Controller
{
    /**
     * GET /admin/pet/notifications
     *
     * Paginated list of pet notification logs with optional filters.
     * Filters: status, channel, notification_type, owner_id, search (title/body)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $query = NotificationLog::query()
            ->orderByDesc('created_at');

        // ── Filters ───────────────────────────────────────────────────────────
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($channel = $request->get('channel')) {
            $query->where('channel', $channel);
        }

        if ($type = $request->get('notification_type')) {
            $query->where('notification_type', $type);
        }

        if ($ownerId = $request->get('owner_id')) {
            $query->where('owner_id', $ownerId);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('body',  'like', "%{$search}%");
            });
        }

        if ($from = $request->get('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->get('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // ── Totals by status (for the current filter set, minus pagination) ──
        // reorder() removes the ORDER BY added above — incompatible with GROUP BY
        // in MySQL's only_full_group_by strict mode.
        $totals = (clone $query)
            ->reorder()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $page,
            'totals'  => $totals,
        ]);
    }

    /**
     * GET /admin/pet/notifications/stats
     *
     * Aggregated stats: totals by status, channel breakdown, 7-day daily activity.
     */
    public function stats(): JsonResponse
    {
        // ── Overall totals ────────────────────────────────────────────────────
        $byStatus = NotificationLog::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $total     = array_sum($byStatus);
        $sent      = ($byStatus['sent']      ?? 0) + ($byStatus['delivered'] ?? 0);
        $delivered = $byStatus['delivered']  ?? 0;
        $pending   = $byStatus['pending']    ?? 0;
        $failed    = $byStatus['failed']     ?? 0;

        // ── By channel ────────────────────────────────────────────────────────
        $byChannel = NotificationLog::query()
            ->select('channel', DB::raw('count(*) as total'))
            ->groupBy('channel')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['channel' => $row->channel, 'total' => (int) $row->total]);

        // ── By notification type ──────────────────────────────────────────────
        $byType = NotificationLog::query()
            ->select('notification_type', DB::raw('count(*) as total'))
            ->groupBy('notification_type')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['type' => $row->notification_type, 'total' => (int) $row->total]);

        // ── Daily activity — last 7 days ──────────────────────────────────────
        $daily = NotificationLog::query()
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as total'),
                DB::raw('sum(case when status in ("sent","delivered") then 1 else 0 end) as sent'),
                DB::raw('sum(case when status = "failed" then 1 else 0 end) as failed'),
            )
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date'   => $row->date,
                'total'  => (int) $row->total,
                'sent'   => (int) $row->sent,
                'failed' => (int) $row->failed,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'total'     => $total,
                'sent'      => $sent,
                'delivered' => $delivered,
                'pending'   => $pending,
                'failed'    => $failed,
                'by_channel' => $byChannel,
                'by_type'   => $byType,
                'daily'     => $daily,
            ],
        ]);
    }
}
