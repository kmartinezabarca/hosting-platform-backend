<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\ReminderSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = ReminderSetting::firstOrCreate(
            ['owner_id' => $request->user()->uuid],
            ['reminder_days' => [30, 14, 7, 3, 1]]
        );

        return response()->json($this->format($settings));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'            => 'sometimes|boolean',
            'emailNotifications' => 'sometimes|boolean',
            'reminderDays'       => 'sometimes|array',
            'reminderDays.*'     => 'integer|min:1|max:365',
            'vaccineReminders'   => 'sometimes|boolean',
            'dewormingReminders' => 'sometimes|boolean',
            'checkupReminders'   => 'sometimes|boolean',
        ]);

        $settings = ReminderSetting::updateOrCreate(
            ['owner_id' => $request->user()->uuid],
            array_filter([
                'enabled'             => $data['enabled'] ?? null,
                'email_notifications' => $data['emailNotifications'] ?? null,
                'reminder_days'       => $data['reminderDays'] ?? null,
                'vaccine_reminders'   => $data['vaccineReminders'] ?? null,
                'deworming_reminders' => $data['dewormingReminders'] ?? null,
                'checkup_reminders'   => $data['checkupReminders'] ?? null,
            ], fn($v) => $v !== null)
        );

        return response()->json($this->format($settings->fresh()));
    }

    private function format(ReminderSetting $s): array
    {
        return [
            'enabled'            => $s->enabled,
            'emailNotifications' => $s->email_notifications,
            'reminderDays'       => $s->reminder_days ?? [30, 14, 7, 3, 1],
            'vaccineReminders'   => $s->vaccine_reminders,
            'dewormingReminders' => $s->deworming_reminders,
            'checkupReminders'   => $s->checkup_reminders,
        ];
    }
}
