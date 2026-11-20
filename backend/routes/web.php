<?php

use App\Http\Controllers\Dashboard;
use Illuminate\Support\Facades\Route;

// Langganan jadwal (.ics): tanpa login, token rahasia di URL.
Route::get('jadwal/{token}.ics', [App\Http\Controllers\CalendarFeedController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('calendar.feed');

// Pendaftaran anggota mandiri (tautan publik dari pengurus).
Route::get('daftar/{token}', [App\Http\Controllers\Publik\RegistrationController::class, 'show'])->where('token', '[A-Za-z0-9]{24}')->name('register.show');
Route::post('daftar/{token}', [App\Http\Controllers\Publik\RegistrationController::class, 'store'])->where('token', '[A-Za-z0-9]{24}')->middleware('throttle:5,1')->name('register.store');
Route::get('daftar/{token}/terkirim', [App\Http\Controllers\Publik\RegistrationController::class, 'done'])->where('token', '[A-Za-z0-9]{24}')->name('register.done');

Route::middleware('guest')->group(function () {
    Route::get('masuk', [Dashboard\AuthController::class, 'show'])->name('login');
    Route::post('masuk', [Dashboard\AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('masuk/2fa', [Dashboard\AuthController::class, 'showTwoFactor'])->name('login.2fa');
    Route::post('masuk/2fa', [Dashboard\AuthController::class, 'twoFactor'])->middleware('throttle:6,1');
    Route::get('lupa-password', [Dashboard\PasswordResetController::class, 'request'])->name('password.request');
    Route::post('lupa-password', [Dashboard\PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('atur-password/{token}', [Dashboard\PasswordResetController::class, 'show'])->name('password.reset');
    Route::post('atur-password', [Dashboard\PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

// Portal anggota (web, untuk yang tidak memakai app Android).
Route::middleware(['auth:web', 'portal'])->prefix('saya')->name('portal.')->group(function () {
    Route::get('/', [App\Http\Controllers\Portal\PortalController::class, 'qr'])->name('qr');
    Route::get('kegiatan', [App\Http\Controllers\Portal\PortalController::class, 'events'])->name('events');
    Route::post('izin', [App\Http\Controllers\Portal\PortalController::class, 'storeExcuse'])->middleware('throttle:10,1')->name('excuse');
    Route::get('riwayat', [App\Http\Controllers\Portal\PortalController::class, 'history'])->name('history');
    Route::get('foto', [App\Http\Controllers\Api\MeController::class, 'photo'])->name('photo');
    Route::get('kartu.pdf', [App\Http\Controllers\Api\MeController::class, 'cardPdf'])->middleware('throttle:10,1')->name('card-pdf');
    Route::get('e-profil', [App\Http\Controllers\Portal\PortalController::class, 'profileForm'])->name('profile');
    Route::post('e-profil', [App\Http\Controllers\Portal\PortalController::class, 'submitProfile'])->middleware('throttle:5,1')->name('profile.store');
    Route::get('rapor', [App\Http\Controllers\Portal\PortalController::class, 'reportCard'])->middleware('throttle:10,1')->name('report-card');
    Route::get('akun', [App\Http\Controllers\Portal\PortalController::class, 'account'])->name('account');
    Route::put('password', [App\Http\Controllers\Portal\PortalController::class, 'password'])->middleware('throttle:10,1')->name('password');
    Route::post('kalender', [App\Http\Controllers\CalendarFeedController::class, 'reset'])->name('calendar');
    Route::post('notifikasi', [App\Http\Controllers\Portal\PortalController::class, 'subscribePush'])->middleware('throttle:20,1')->name('push.subscribe');
    Route::delete('notifikasi', [App\Http\Controllers\Portal\PortalController::class, 'unsubscribePush'])->name('push.unsubscribe');
    Route::post('notifikasi/uji', [App\Http\Controllers\Portal\PortalController::class, 'testPush'])->middleware('throttle:5,1')->name('push.test');
});

// Mode petugas di browser: pindai QR Saya dengan kamera.
Route::middleware(['auth:web', 'scanner.web'])->prefix('pindai')->name('scanner.')->group(function () {
    Route::get('/', [App\Http\Controllers\Scanner\ScannerController::class, 'index'])->name('index');
    Route::get('password', [App\Http\Controllers\Scanner\ScannerController::class, 'password'])->name('password');
    Route::put('password', [App\Http\Controllers\Scanner\ScannerController::class, 'updatePassword'])->middleware('throttle:10,1')->name('password.update');
    Route::get('{event}', [App\Http\Controllers\Scanner\ScannerController::class, 'show'])->whereNumber('event')->name('show');
    Route::post('{event}/scan', [App\Http\Controllers\Scanner\ScannerController::class, 'scan'])->whereNumber('event')->middleware('throttle:120,1')->name('scan');
    Route::get('{event}/foto/{member}', [Dashboard\EventMonitorController::class, 'photo'])->whereNumber(['event', 'member'])->name('photo');
});

Route::middleware(['auth:web', 'dashboard'])->group(function () {
    Route::post('keluar', [Dashboard\AuthController::class, 'logout'])->name('logout');
    Route::get('/', Dashboard\HomeController::class)->name('home');
    Route::get('cari', Dashboard\SearchController::class)->middleware('throttle:60,1')->name('search');
    Route::get('akun', [Dashboard\AccountController::class, 'edit'])->name('account');
    Route::put('akun/ringkasan', [Dashboard\AccountController::class, 'weeklySummary'])->name('account.weekly-summary');
    Route::get('akun/ringkasan/contoh', [Dashboard\AccountController::class, 'weeklySummaryPreview'])->name('account.weekly-summary.preview');
    Route::post('akun/kalender', [App\Http\Controllers\CalendarFeedController::class, 'reset'])->name('account.calendar.reset');
    Route::put('akun/password', [Dashboard\AccountController::class, 'password'])->name('account.password');
    Route::post('akun/2fa', [Dashboard\AccountController::class, 'startTwoFactor'])->name('account.2fa.start');
    Route::post('akun/2fa/konfirmasi', [Dashboard\AccountController::class, 'confirmTwoFactor'])->middleware('throttle:10,1')->name('account.2fa.confirm');
    Route::delete('akun/2fa', [Dashboard\AccountController::class, 'disableTwoFactor'])->name('account.2fa.disable');

    // Operasional — Super Admin & Admin Divisi (akses divisi dicek di layanan)
    Route::resource('kegiatan', Dashboard\EventController::class)->parameters(['kegiatan' => 'event'])->names('events');
    Route::post('kegiatan/{event}/tutup', [Dashboard\EventController::class, 'close'])->name('events.close');
    Route::post('kegiatan/{event}/duplikat', [Dashboard\EventController::class, 'duplicate'])->name('events.duplicate');
    Route::get('kegiatan/{event}/monitor', [Dashboard\EventMonitorController::class, 'show'])->name('events.monitor');
    Route::get('kegiatan/{event}/monitor/foto/{member}', [Dashboard\EventMonitorController::class, 'photo'])->name('events.monitor.photo');
    Route::put('kegiatan/{event}/target', [Dashboard\EventController::class, 'targets'])->name('events.targets');
    Route::post('kegiatan/{event}/sesi', [Dashboard\EventController::class, 'storeSession'])->name('events.sessions.store');
    Route::put('kegiatan/{event}/sesi/{session}', [Dashboard\EventController::class, 'updateSession'])->name('events.sessions.update');
    Route::delete('kegiatan/{event}/sesi/{session}', [Dashboard\EventController::class, 'destroySession'])->name('events.sessions.destroy');
    Route::patch('absensi/{attendance}', [Dashboard\EventController::class, 'correct'])->name('attendances.correct');
    Route::get('izin', [Dashboard\ExcuseController::class, 'index'])->name('excuses.index');
    Route::post('izin', [Dashboard\ExcuseController::class, 'store'])->name('excuses.store');
    Route::post('izin/massal', [Dashboard\ExcuseController::class, 'reviewMany'])->name('excuses.review-many');
    Route::patch('izin/{excuse}', [Dashboard\ExcuseController::class, 'review'])->name('excuses.review');
    Route::get('izin/{excuse}/bukti', [Dashboard\ExcuseController::class, 'attachment'])->name('excuses.attachment');
    Route::resource('pengumuman', Dashboard\AnnouncementController::class)->parameters(['pengumuman' => 'announcement'])->names('announcements')->only(['index', 'store', 'update', 'destroy']);
    Route::get('tinjauan', [Dashboard\ReviewController::class, 'index'])->name('reviews.index');
    Route::post('tinjauan/{attendance}/terima', [Dashboard\ReviewController::class, 'accept'])->name('reviews.accept');
    Route::post('tinjauan/{attendance}/batalkan', [Dashboard\ReviewController::class, 'void'])->name('reviews.void');
    Route::get('rekap', [Dashboard\ReportController::class, 'index'])->name('reports.index');
    Route::post('ekspor', [Dashboard\ReportController::class, 'export'])->name('exports.store');
    Route::get('ekspor/{export}/unduh', [Dashboard\ReportController::class, 'download'])->name('exports.download');

    // Anggota: lihat (Admin Divisi: divisinya saja, dicek di MemberService)
    Route::get('anggota', [Dashboard\MemberController::class, 'index'])->name('members.index');
    Route::get('anggota/{member}', [Dashboard\MemberController::class, 'show'])->whereNumber('member')->name('members.show');
    Route::get('anggota/{member}/foto', [Dashboard\MemberController::class, 'photo'])->whereNumber('member')->name('members.photo');
    Route::get('anggota/{member}/rapor', [Dashboard\MemberController::class, 'reportCard'])->whereNumber('member')->name('members.report-card');

    // Data & Administrasi — Super Admin
    Route::middleware('role:super_admin')->group(function () {
        Route::get('anggota/impor', [Dashboard\MemberController::class, 'importForm'])->name('members.import');
        Route::post('anggota/impor', [Dashboard\MemberController::class, 'importPreview'])->name('members.import.preview');
        Route::post('anggota/massal', [Dashboard\MemberController::class, 'bulk'])->name('members.bulk');
        Route::post('anggota/akun-app', [Dashboard\MemberController::class, 'createAccounts'])->name('members.accounts');
        Route::get('anggota/kartu-cetak', [Dashboard\MemberController::class, 'printCards'])->middleware('throttle:20,1')->name('members.print-cards');
        Route::get('anggota/akun-app/hasil', [Dashboard\MemberController::class, 'accountSlips'])->name('members.accounts.slips');
        Route::post('anggota/impor/simpan', [Dashboard\MemberController::class, 'importCommit'])->name('members.import.commit');
        Route::resource('anggota', Dashboard\MemberController::class)->parameters(['anggota' => 'member'])->names('members')->except(['index', 'show']);
        Route::post('anggota/{member}/pulihkan', [Dashboard\MemberController::class, 'restore'])->withTrashed()->name('members.restore');
        Route::post('anggota/{member}/foto', [Dashboard\MemberController::class, 'uploadPhoto'])->name('members.photo.upload');
        Route::post('anggota/{member}/persetujuan', [Dashboard\MemberController::class, 'consent'])->name('members.consent');
        Route::post('anggota/{member}/hapus-data', [Dashboard\MemberController::class, 'anonymize'])->name('members.anonymize');
        Route::get('kartu', [Dashboard\CardController::class, 'index'])->name('cards.index');
        Route::post('kartu/{card}/blokir', [Dashboard\CardController::class, 'block'])->name('cards.block');
        Route::post('kartu/permintaan/{request}', [Dashboard\CardController::class, 'reviewRequest'])->name('cards.requests.review');
        Route::resource('pengguna', Dashboard\UserController::class)->parameters(['pengguna' => 'user'])->names('users')->except(['show', 'destroy']);
        Route::post('pengguna/{user}/reset-password', [Dashboard\UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('pengguna/{user}/status', [Dashboard\UserController::class, 'toggle'])->name('users.toggle');
        Route::post('pengguna/{user}/reset-2fa', [Dashboard\UserController::class, 'resetTwoFactor'])->name('users.reset-2fa');
        Route::get('perangkat', [Dashboard\DeviceController::class, 'index'])->name('devices.index');
        Route::post('perangkat/{device}/cabut', [Dashboard\DeviceController::class, 'revoke'])->name('devices.revoke');
        Route::get('log', [Dashboard\LogController::class, 'index'])->name('logs.index');
        Route::get('pengaturan', [Dashboard\SettingsController::class, 'index'])->name('settings.index');
        Route::post('pengaturan/pendaftaran', [Dashboard\SettingsController::class, 'registration'])->name('settings.registration');
        Route::post('pengaturan/email-uji', [Dashboard\SettingsController::class, 'testMail'])->middleware('throttle:5,1')->name('settings.test-mail');
        Route::put('pengaturan/organisasi', [Dashboard\SettingsController::class, 'updateOrganization'])->name('settings.organization');
        Route::put('pengaturan/privasi', [Dashboard\SettingsController::class, 'updatePrivacy'])->name('settings.privacy');
        Route::put('pengaturan/denda', [Dashboard\SettingsController::class, 'updateFines'])->name('settings.fines');
        Route::get('denda', [Dashboard\FineController::class, 'index'])->name('fines.index');
        Route::post('denda/tandai', [Dashboard\FineController::class, 'mark'])->name('fines.mark');
        Route::post('pengaturan/periode', [Dashboard\SettingsController::class, 'storeTerm'])->name('settings.terms.store');
        Route::post('pengaturan/periode/{term}/berjalan', [Dashboard\SettingsController::class, 'setCurrentTerm'])->name('settings.terms.current');
        Route::put('pengaturan/periode/{term}', [Dashboard\SettingsController::class, 'updateTerm'])->name('settings.terms.update');
        Route::delete('pengaturan/periode/{term}', [Dashboard\SettingsController::class, 'destroyTerm'])->name('settings.terms.destroy');
        Route::post('pengaturan/divisi', [Dashboard\SettingsController::class, 'storeDivision'])->name('settings.divisions.store');
        Route::put('pengaturan/divisi/{division}', [Dashboard\SettingsController::class, 'updateDivision'])->name('settings.divisions.update');
        Route::delete('pengaturan/divisi/{division}', [Dashboard\SettingsController::class, 'destroyDivision'])->name('settings.divisions.destroy');
    });
});
