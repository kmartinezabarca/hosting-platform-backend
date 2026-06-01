<?php

namespace App\Domains\Pet\Console\Commands;

use App\Domains\Pet\Mail\PetReminderMail;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\MedicalRecord;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Models\ReminderSetting;
use App\Domains\Pet\Models\Vaccine;
use App\Domains\Pet\Contracts\UserDirectory;
use App\Domains\Pet\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendPetReminders extends Command
{
    protected $signature   = 'rokepet:send-pet-reminders';
    protected $description = 'Send email + push reminders for pet vaccines, deworming and checkups (roke.pet)';

    public function __construct(
        private readonly PushNotificationService $push,
        private readonly UserDirectory $users,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $defaultDays = [1, 3, 7, 14, 30];
        $today       = Carbon::today();
        $totalSent   = 0;

        $allSettings = ReminderSetting::where('enabled', true)->get();

        $this->info("Processing {$allSettings->count()} owner(s) with reminders enabled.");

        foreach ($allSettings as $setting) {
            $days = $setting->reminder_days ?? $defaultDays;

            $owner = Owner::find($setting->owner_id);
            if (! $owner) continue;

            $userEmail = $this->users->getEmail($owner->id);

            $petIds = $owner->pets()->pluck('id');
            if ($petIds->isEmpty()) continue;

            // ── 1. Vacunas ──────────────────────────────────────────────────
            if ($setting->vaccine_reminders) {
                $totalSent += $this->processVaccineReminders(
                    $owner, $userEmail, $petIds->toArray(), $days, $today
                );
            }

            // ── 2. Desparasitaciones (follow_up_date de registros tipo deworming) ──
            if ($setting->deworming_reminders) {
                $totalSent += $this->processMedicalRecordReminders(
                    $owner, $userEmail, $petIds->toArray(), $days, $today, 'deworming'
                );
            }

            // ── 3. Consultas de seguimiento (follow_up_date tipo checkup) ──
            if ($setting->checkup_reminders) {
                $totalSent += $this->processMedicalRecordReminders(
                    $owner, $userEmail, $petIds->toArray(), $days, $today, 'checkup'
                );
            }
        }

        $this->info("Done. Total notifications sent: {$totalSent}.");
        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function processVaccineReminders(
        Owner  $owner,
        ?string $email,
        array  $petIds,
        array  $days,
        Carbon $today,
    ): int {
        $sent = 0;

        $vaccines = Vaccine::whereIn('pet_id', $petIds)
            ->whereNotNull('next_due')
            ->where('status', '!=', 'overdue')
            ->get();

        foreach ($vaccines as $vaccine) {
            $dueDate      = Carbon::parse($vaccine->next_due);
            $daysUntilDue = (int) $today->diffInDays($dueDate, false);

            if ($daysUntilDue < 0 || ! in_array($daysUntilDue, $days)) continue;

            if ($this->alreadySent($vaccine->id, 'vaccine', $today)) continue;

            $pet = $vaccine->pet;
            $this->dispatch(
                owner:        $owner,
                email:        $email,
                petName:      $pet->name,
                eventName:    $vaccine->name,
                dueDate:      $dueDate->format('d/m/Y'),
                daysUntilDue: $daysUntilDue,
                type:         'vaccine',
                referenceId:  $vaccine->id,
                url:          "/dashboard/{$pet->id}",
            );
            $sent++;
        }

        return $sent;
    }

    private function processMedicalRecordReminders(
        Owner  $owner,
        ?string $email,
        array  $petIds,
        array  $days,
        Carbon $today,
        string $type,  // 'deworming' | 'checkup'
    ): int {
        $sent = 0;

        $records = MedicalRecord::whereIn('pet_id', $petIds)
            ->where('type', $type)
            ->whereNotNull('follow_up_date')
            ->get();

        foreach ($records as $record) {
            $followUp     = Carbon::parse($record->follow_up_date);
            $daysUntilDue = (int) $today->diffInDays($followUp, false);

            if ($daysUntilDue < 0 || ! in_array($daysUntilDue, $days)) continue;

            if ($this->alreadySent($record->id, $type, $today)) continue;

            $pet = $record->pet;
            $this->dispatch(
                owner:        $owner,
                email:        $email,
                petName:      $pet->name,
                eventName:    $record->description ?: match ($type) {
                    'deworming' => 'Desparasitación',
                    default     => 'Consulta de seguimiento',
                },
                dueDate:      $followUp->format('d/m/Y'),
                daysUntilDue: $daysUntilDue,
                type:         $type,
                referenceId:  $record->id,
                url:          "/dashboard/{$pet->id}",
            );
            $sent++;
        }

        return $sent;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function dispatch(
        Owner   $owner,
        ?string $email,
        string  $petName,
        string  $eventName,
        string  $dueDate,
        int     $daysUntilDue,
        string  $type,
        string  $referenceId,
        string  $url,
    ): void {
        $channels = [];

        // Email
        if ($email) {
            try {
                Mail::to($email)->send(new PetReminderMail(
                    ownerName:    $owner->display_name ?: 'Dueño',
                    petName:      $petName,
                    eventName:    $eventName,
                    dueDate:      $dueDate,
                    daysUntilDue: $daysUntilDue,
                    type:         $type,
                ));
                $channels[] = 'email';
            } catch (\Throwable $e) {
                Log::error('[rokepet] Email reminder failed', [
                    'owner' => $owner->id, 'ref' => $referenceId, 'error' => $e->getMessage(),
                ]);
            }
        }

        // Push notification
        try {
            $pushTitle = match ($type) {
                'vaccine'   => "💉 Vacuna de {$petName}",
                'deworming' => "🐛 Desparasitación de {$petName}",
                'checkup'   => "🩺 Consulta de seguimiento — {$petName}",
                default     => "📋 Recordatorio — {$petName}",
            };

            $pushBody = $daysUntilDue === 0
                ? "{$eventName} vence hoy."
                : "{$eventName} vence en {$daysUntilDue} día(s) ({$dueDate}).";

            $pushed = $this->push->sendToOwner($owner->id, $pushTitle, $pushBody, ['url' => $url]);

            if ($pushed > 0) {
                $channels[] = 'push';
                InboxNotification::createForOwner(
                    ownerId:   $owner->id,
                    title:     $pushTitle,
                    body:      $pushBody,
                    notifType: "reminder_{$type}",
                    url:       $url,
                    tag:       "reminder-{$type}-{$referenceId}",
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[rokepet] Push reminder failed', ['owner' => $owner->id, 'error' => $e->getMessage()]);
        }

        if (! empty($channels)) {
            $this->recordSent($owner->id, $referenceId, $type, implode('+', $channels));
        }
    }

    private function alreadySent(string $referenceId, string $referenceType, Carbon $today): bool
    {
        return DB::connection('roke_pet')
            ->table('sent_reminders')
            ->where('reference_id', $referenceId)
            ->where('reference_type', $referenceType)
            ->whereDate('sent_at', $today)
            ->exists();
    }

    private function recordSent(string $ownerId, string $referenceId, string $referenceType, string $channel): void
    {
        DB::connection('roke_pet')->table('sent_reminders')->insert([
            'id'             => Str::uuid()->toString(),
            'owner_id'       => $ownerId,
            'reference_id'   => $referenceId,
            'reference_type' => $referenceType,
            'sent_at'        => now(),
            'channel'        => $channel,
        ]);
    }
}
