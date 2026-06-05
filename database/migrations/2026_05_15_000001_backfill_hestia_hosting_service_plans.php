<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaultPackage = config('hestia.default_package', 'default');

        DB::table('service_plans')
            ->join('categories', 'categories.id', '=', 'service_plans.category_id')
            ->where('categories.slug', 'hosting')
            ->select([
                'service_plans.id',
                'service_plans.provisioner_config',
                'service_plans.hestia_package',
            ])
            ->orderBy('service_plans.id')
            ->each(function (object $plan) use ($defaultPackage) {
                $config = [];

                if (is_string($plan->provisioner_config) && $plan->provisioner_config !== '') {
                    $decoded = json_decode($plan->provisioner_config, true);
                    $config = is_array($decoded) ? $decoded : [];
                }

                $package = $config['package'] ?? $plan->hestia_package ?? $defaultPackage;

                DB::table('service_plans')
                    ->where('id', $plan->id)
                    ->update([
                        'provisioner' => 'hestia',
                        'hestia_package' => $package,
                        'provisioner_config' => json_encode([
                            'package' => $package,
                            'web_template' => $config['web_template'] ?? 'default',
                            'dns_template' => $config['dns_template'] ?? 'default',
                            'mail_enabled' => array_key_exists('mail_enabled', $config) ? (bool) $config['mail_enabled'] : true,
                            'db_enabled' => array_key_exists('db_enabled', $config) ? (bool) $config['db_enabled'] : true,
                        ]),
                    ]);
            });
    }

    public function down(): void
    {
        // Intentional no-op: this migration backfills production data and should
        // not erase provisioner metadata on rollback.
    }
};
