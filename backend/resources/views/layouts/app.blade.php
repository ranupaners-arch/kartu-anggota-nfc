<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Dashboard' }} · {{ auth()->user()->organization?->name ?? config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body>
@php
    $u = auth()->user();
    $super = $u->isSuperAdmin();
    // Navigasi dua tingkat: menu (grup) → submenu (halaman).
    $menu = [
        [null, [['Beranda', 'home', 'home', null]]],
        ['Operasional', [
            ['Kegiatan', 'events.index', 'events.*', null],
            ['Pengajuan Izin', 'excuses.index', 'excuses.*', $navBadges['excuses'] ?? 0],
            ['Perlu Ditinjau', 'reviews.index', 'reviews.*', $navBadges['reviews'] ?? 0],
        ]],
        ['Data', array_values(array_filter([
            $super ? ['Anggota', 'members.index', 'members.*', null] : null,
            $super ? ['Kartu', 'cards.index', 'cards.*', null] : null,
            ['Rekap & Ekspor', 'reports.index', 'reports.*', null],
        ]))],
        $super ? ['Administrasi', [
            ['Pengguna & Peran', 'users.index', 'users.*', null],
            ['Perangkat Petugas', 'devices.index', 'devices.*', null],
            ['Log Aktivitas', 'logs.index', 'logs.*', null],
            ['Pengaturan', 'settings.index', 'settings.*', null],
        ]] : null,
    ];
@endphp
<div class="app">
    <aside class="sidebar">
        <div class="brand">{{ $u->organization?->name ?? config('app.name') }}</div>
        @foreach (array_filter($menu) as [$group, $items])
            @if ($group)<div class="group">{{ $group }}</div>@endif
            @foreach ($items as [$label, $route, $pattern, $badge])
                <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'active' : '' }}">
                    <span>{{ $label }}</span>@if ($badge)<span class="badge">{{ $badge }}</span>@endif
                </a>
            @endforeach
        @endforeach
    </aside>
    <div class="main">
        <div class="topbar">
            <span class="muted">{{ $u->name }} · {{ str_replace('_', ' ', $u->role) }}</span>
            <a href="{{ route('account') }}">Akun Saya</a>
            <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button class="btn-secondary">Keluar</button></form>
        </div>
        <div class="content">
            @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
            @if ($errors->any())
                <div class="errors">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
            @endif
            @yield('content')
        </div>
    </div>
</div>
</body>
</html>
