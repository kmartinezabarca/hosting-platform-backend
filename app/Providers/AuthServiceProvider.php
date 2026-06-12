<?php

namespace App\Providers;

use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Models\Quotation;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Policies\QuotationPolicy;
use App\Policies\ResourcePolicy;
use App\Policies\TeamPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class      => UserPolicy::class,
        Quotation::class => QuotationPolicy::class,
        // Plano de cómputo (autorización por membresía de equipo)
        Team::class      => TeamPolicy::class,
        Project::class   => ProjectPolicy::class,
        Resource::class  => ResourcePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
