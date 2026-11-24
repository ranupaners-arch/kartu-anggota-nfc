<?php

namespace App\Providers;

use App\Services\AttendanceService;
use App\Services\ExcuseService;
use App\Services\Push\PushTransport;
use App\Services\Push\WebPushTransport;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PushTransport::class, WebPushTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Badge jumlah pekerjaan di menu dashboard.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            if (! $user) {
                return;
            }
            $view->with('navBadges', [
                'excuses' => app(ExcuseService::class)->visibleTo($user)->where('status', 'pending')->count(),
                'reviews' => app(AttendanceService::class)->pendingReviewCount($user),
            ]);
        });
        Paginator::useBootstrapFive();
    }
}
