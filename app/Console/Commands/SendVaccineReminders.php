<?php

namespace App\Console\Commands;

use App\Mail\RokePet\VaccineReminderMail;
use App\Models\RokePet\Owner;
use App\Models\RokePet\ReminderSetting;
use App\Models\RokePet\Vaccine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendVaccineReminders extends Command
{
    protected $signature   = 'rokepet:send-vaccine-reminders';
    protected $description = 'Send email reminders for upcoming pet vaccines (roke.pet)';

    public function handle(): int
    {
        $defaultDays = [1, 3, 7, 14, 30];
        $today       = Carbon::today();

        $settings = ReminderSetting::where('enabled', true)
            ->where('email_notifications', true)
            ->where('vaccine_reminders', true)
            ->get();

        $this->info("Processing {$settings->count()} owners with vaccine reminders enabled.");

        $sent = 0;

        foreach ($settings as $setting) {
            $days = $setting->reminder_days ?? $defaultDays;

            $owner = Owner::find($setting->owner_id);
            if (! $owner) {
                continue;
            }

            // Get the user email from the main DB using the owner UUID
            $userEmail = DB::connection('mysql')
                ->table('users')
                ->where('uuid', $owner->id)
                ->value('email');

            if (! $userEmail) {
                continue;
            }

            $pets = $owner->pets()->pluck('id');

            $vaccines = Vaccine::whereIn('pet_id', $pets)
                ->whereNotNull('next_due')
                ->where('status', '!=', 'overdue')
                ->get();

            foreach ($vaccines as $vaccine) {
                $nextDue     = Carbon::parse($vaccine->next_due);
                $daysUntilDue = (int) $today->diffInDays($nextDue, false);

                if ($daysUntilDue < 0 || ! in_array($daysUntilDue, $days)) {
                    continue;
                }

                // Idempotency: one reminder per vaccine per day
                $alreadySent = DB::connection('roke_pet')
                    ->table('sent_reminders')
                    ->where('vaccine_id', $vaccine->id)
                    ->whereDate('sent_at', $today)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                $pet = $vaccine->pet;

                try {
                    Mail::to($userEmail)->send(new VaccineReminderMail(
                        ownerName:    $owner->display_name ?: 'Dueño',
                        petName:      $pet->name,
                        vaccineName:  $vaccine->name,
                        dueDate:      $nextDue->format('d/m/Y'),
                        daysUntilDue: $daysUntilDue,
                    ));

                    DB::connection('roke_pet')->table('sent_reminders')->insert([
                        'id'         => \Illuminate\Support\Str::uuid()->toString(),
                        'owner_id'   => $owner->id,
                        'vaccine_id' => $vaccine->id,
                        'sent_at'    => now(),
                        'channel'    => 'email',
                    ]);

                    $sent++;
                } catch (\Throwable $e) {
                    Log::channel('stack')->error('[rokepet] Vaccine reminder failed', [
                        'owner'   => $owner->id,
                        'vaccine' => $vaccine->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Sent {$sent} vaccine reminder(s).");

        return self::SUCCESS;
    }
}
