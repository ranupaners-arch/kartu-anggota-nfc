<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Badge jumlah pekerjaan di menu dashboard.
        \Illuminate\Support\Facades\View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            if (! $user) {
                return;
            }
            $view->with('navBadges', [
                'excuses' => app(\App\Services\ExcuseService::class)->visibleTo($user)->where('status', 'pending')->count(),
                'reviews' => app(\App\Services\AttendanceService::class)->pendingReviewCount($user),
            ]);
        });
        \Illuminate\Pagination\Paginator::useBootstrapFive();
    }
}
