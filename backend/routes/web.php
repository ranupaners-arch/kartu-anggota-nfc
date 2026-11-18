<?php

use App\Http\Controllers\Dashboard;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('masuk', [Dashboard\AuthController::class, 'show'])->name('login');
    Route::post('masuk', [Dashboard\AuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware(['auth:web', 'dashboard'])->group(function () {
    Route::post('keluar', [Dashboard\AuthController::class, 'logout'])->name('logout');
    Route::get('/', Dashboard\HomeController::class)->name('home');
    Route::get('akun', [Dashboard\AccountController::class, 'edit'])->name('account');
    Route::put('akun/password', [Dashboard\AccountController::class, 'password'])->name('account.password');

    // Operasional — Super Admin & Admin Divisi (akses divisi dicek di layanan)
    Route::resource('kegiatan', Dashboard\EventController::class)->parameters(['kegiatan' => 'event'])->names('events');
    Route::post('kegiatan/{event}/tutup', [Dashboard\EventController::class, 'close'])->name('events.close');
    Route::get('kegiatan/{event}/monitor', [Dashboard\EventMonitorController::class, 'show'])->name('events.monitor');
    Route::get('kegiatan/{event}/monitor/foto/{member}', [Dashboard\EventMonitorController::class, 'photo'])->name('events.monitor.photo');
    Route::put('kegiatan/{event}/target', [Dashboard\EventController::class, 'targets'])->name('events.targets');
    Route::post('kegiatan/{event}/sesi', [Dashboard\EventController::class, 'storeSession'])->name('events.sessions.store');
    Route::patch('absensi/{attendance}', [Dashboard\EventController::class, 'correct'])->name('attendances.correct');
    Route::get('izin', [Dashboard\ExcuseController::class, 'index'])->name('excuses.index');
    Route::patch('izin/{excuse}', [Dashboard\ExcuseController::class, 'review'])->name('excuses.review');
    Route::get('izin/{excuse}/bukti', [Dashboard\ExcuseController::class, 'attachment'])->name('excuses.attachment');
    Route::get('tinjauan', [Dashboard\ReviewController::class, 'index'])->name('reviews.index');
    Route::post('tinjauan/{attendance}/terima', [Dashboard\ReviewController::class, 'accept'])->name('reviews.accept');
    Route::post('tinjauan/{attendance}/batalkan', [Dashboard\ReviewController::class, 'void'])->name('reviews.void');
    Route::get('rekap', [Dashboard\ReportController::class, 'index'])->name('reports.index');
    Route::post('ekspor', [Dashboard\ReportController::class, 'export'])->name('exports.store');
    Route::get('ekspor/{export}/unduh', [Dashboard\ReportController::class, 'download'])->name('exports.download');

    // Data & Administrasi — Super Admin
    Route::middleware('role:super_admin')->group(function () {
        Route::get('anggota/impor', [Dashboard\MemberController::class, 'importForm'])->name('members.import');
        Route::post('anggota/impor', [Dashboard\MemberController::class, 'importPreview'])->name('members.import.preview');
        Route::post('anggota/impor/simpan', [Dashboard\MemberController::class, 'importCommit'])->name('members.import.commit');
        Route::resource('anggota', Dashboard\MemberController::class)->parameters(['anggota' => 'member'])->names('members');
        Route::get('anggota/{member}/foto', [Dashboard\MemberController::class, 'photo'])->name('members.photo');
        Route::post('anggota/{member}/foto', [Dashboard\MemberController::class, 'uploadPhoto'])->name('members.photo.upload');
        Route::get('kartu', [Dashboard\CardController::class, 'index'])->name('cards.index');
        Route::post('kartu/{card}/blokir', [Dashboard\CardController::class, 'block'])->name('cards.block');
        Route::resource('pengguna', Dashboard\UserController::class)->parameters(['pengguna' => 'user'])->names('users')->except(['show', 'destroy']);
        Route::post('pengguna/{user}/reset-password', [Dashboard\UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('pengguna/{user}/status', [Dashboard\UserController::class, 'toggle'])->name('users.toggle');
        Route::get('perangkat', [Dashboard\DeviceController::class, 'index'])->name('devices.index');
        Route::post('perangkat/{device}/cabut', [Dashboard\DeviceController::class, 'revoke'])->name('devices.revoke');
        Route::get('log', [Dashboard\LogController::class, 'index'])->name('logs.index');
        Route::get('pengaturan', [Dashboard\SettingsController::class, 'index'])->name('settings.index');
        Route::put('pengaturan/organisasi', [Dashboard\SettingsController::class, 'updateOrganization'])->name('settings.organization');
        Route::post('pengaturan/periode', [Dashboard\SettingsController::class, 'storeTerm'])->name('settings.terms.store');
        Route::post('pengaturan/periode/{term}/berjalan', [Dashboard\SettingsController::class, 'setCurrentTerm'])->name('settings.terms.current');
        Route::post('pengaturan/divisi', [Dashboard\SettingsController::class, 'storeDivision'])->name('settings.divisions.store');
        Route::put('pengaturan/divisi/{division}', [Dashboard\SettingsController::class, 'updateDivision'])->name('settings.divisions.update');
    });
});
