<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureDashboardAccess;
use App\Http\Middleware\EnsureMemberPortal;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureScannerWeb;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\ErrorMessage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'dashboard' => EnsureDashboardAccess::class,
            'scanner.web' => EnsureScannerWeb::class,
            'portal' => EnsureMemberPortal::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);
        // Di belakang Caddy (jaringan docker) dan opsional Nginx/Cloudflare: percayai X-Forwarded-*
        // dari alamat privat supaya skema https, host, dan IP klien asli dikenali.
        // TRUSTED_PROXIES: daftar IP/CIDR dipisah koma, atau "*".
        $trustedProxies = env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');
        $middleware->trustProxies(
            at: trim($trustedProxies) === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
        );
        $middleware->redirectUsersTo('/');
        $middleware->prepend(AssignRequestId::class);
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pelacakan error (Sentry): aktif hanya bila SENTRY_LARAVEL_DSN diisi.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Navigasi dashboard (Inertia): error HTTP jangan tampil sebagai modal berisi halaman error.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->header('X-Inertia') || $request->is('api/*') || $e->getStatusCode() === 419 || ! auth()->check()) {
                return null;
            }
            $message = ErrorMessage::translate($e->getMessage(), $e->getStatusCode());
            if (! $request->isMethod('GET')) {
                return back()->withErrors(['error' => $message]);
            }

            return Inertia::render('error', ['status' => $e->getStatusCode(), 'message' => $message])
                ->toResponse($request)->setStatusCode($e->getStatusCode());
        });

        // Respons JSON (app & fetch): pesan bawaan berbahasa Inggris diganti Bahasa Indonesia.
        $exceptions->respond(function (Response $response) {
            if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
                $data = $response->getData(true);
                if (is_array($data) && array_key_exists('message', $data)) {
                    $data['message'] = ErrorMessage::translate($data['message'], $response->getStatusCode());
                    $response->setData($data);
                }
            }

            return $response;
        });
    })->create();
