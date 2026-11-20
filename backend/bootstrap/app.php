<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'dashboard' => \App\Http\Middleware\EnsureDashboardAccess::class,
            'scanner.web' => \App\Http\Middleware\EnsureScannerWeb::class,
            'portal' => \App\Http\Middleware\EnsureMemberPortal::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordChanged::class,
        ]);
        $middleware->redirectUsersTo('/');
        $middleware->prepend(\App\Http\Middleware\AssignRequestId::class);
        $middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pelacakan error (Sentry): aktif hanya bila SENTRY_LARAVEL_DSN diisi.
        \Sentry\Laravel\Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Navigasi dashboard (Inertia): error HTTP jangan tampil sebagai modal berisi halaman error.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, Request $request) {
            if (! $request->header('X-Inertia') || $request->is('api/*') || $e->getStatusCode() === 419 || ! auth()->check()) {
                return null;
            }
            $message = \App\Support\ErrorMessage::translate($e->getMessage(), $e->getStatusCode());
            if (! $request->isMethod('GET')) {
                return back()->withErrors(['error' => $message]);
            }

            return \Inertia\Inertia::render('error', ['status' => $e->getStatusCode(), 'message' => $message])
                ->toResponse($request)->setStatusCode($e->getStatusCode());
        });

        // Respons JSON (app & fetch): pesan bawaan berbahasa Inggris diganti Bahasa Indonesia.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if ($response instanceof \Illuminate\Http\JsonResponse && $response->getStatusCode() >= 400) {
                $data = $response->getData(true);
                if (is_array($data) && array_key_exists('message', $data)) {
                    $data['message'] = \App\Support\ErrorMessage::translate($data['message'], $response->getStatusCode());
                    $response->setData($data);
                }
            }

            return $response;
        });
    })->create();
