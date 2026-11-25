<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Publik\CertificateController as PublikCertificateController;
use App\Http\Controllers\Publik\GuestController as PublikGuestController;
use App\Http\Controllers\Publik\RegistrationController;
use App\Http\Controllers\Scanner\ScannerController;
use Illuminate\Support\Facades\Route;

// Langganan jadwal (.ics): tanpa login, token rahasia di URL.
Route::get('jadwal/{token}.ics', [CalendarFeedController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('calendar.feed');

// Pendaftaran anggota mandiri (tautan publik dari pengurus).
Route::get('daftar/{token}', [RegistrationController::class, 'show'])->where('token', '[A-Za-z0-9]{24}')->name('register.show');
Route::post('daftar/{token}', [RegistrationController::class, 'store'])->where('token', '[A-Za-z0-9]{24}')->middleware('throttle:5,1')->name('register.store');
Route::get('daftar/{token}/terkirim', [RegistrationController::class, 'done'])->where('token', '[A-Za-z0-9]{24}')->name('register.done');

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

// QR masuk tamu kegiatan (tautan rahasia dari pengurus).
Route::get('tamu/{token}', [PublikGuestController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('guests.show');

// Cek keaslian sertifikat (publik, dari QR).
Route::get('sertifikat/{code}', [PublikCertificateController::class, 'verify'])->where('code', '[A-Za-z0-9-]{16,25}')->middleware('throttle:30,1')->name('certificates.verify');

// Portal anggota (web, untuk yang tidak memakai app Android).
Route::middleware(['auth:web', 'portal'])->prefix('saya')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'qr'])->name('qr');
    Route::get('kegiatan', [PortalController::class, 'events'])->name('events');
    Route::post('kegiatan/{event}/rsvp', [PortalController::class, 'rsvp'])->middleware('throttle:20,1')->name('rsvp');
    Route::post('izin', [PortalController::class, 'storeExcuse'])->middleware('throttle:10,1')->name('excuse');
    Route::get('riwayat', [PortalController::class, 'history'])->name('history');
    Route::post('survei/{survey}', [PortalController::class, 'answerSurvey'])->whereNumber('survey')->middleware('throttle:20,1')->name('survey');
    Route::get('notulen', [PortalController::class, 'minutes'])->name('minutes');
    Route::get('dokumen/{document}', [MeController::class, 'document'])->whereNumber('document')->middleware('throttle:30,1')->name('document');
    Route::get('sertifikat/{certificate}.pdf', [MeController::class, 'certificatePdf'])->whereNumber('certificate')->middleware('throttle:20,1')->name('certificate');
    Route::put('peringkat', [PortalController::class, 'leaderboard'])->middleware('throttle:20,1')->name('leaderboard');
    Route::get('foto', [MeController::class, 'photo'])->name('photo');
    Route::get('kartu.pdf', [MeController::class, 'cardPdf'])->middleware('throttle:10,1')->name('card-pdf');
    Route::get('e-profil', [PortalController::class, 'profileForm'])->name('profile');
    Route::post('e-profil', [PortalController::class, 'submitProfile'])->middleware('throttle:5,1')->name('profile.store');
    Route::get('rapor', [PortalController::class, 'reportCard'])->middleware('throttle:10,1')->name('report-card');
    Route::get('akun', [PortalController::class, 'account'])->name('account');
    Route::put('password', [PortalController::class, 'password'])->middleware('throttle:10,1')->name('password');
    Route::post('kalender', [CalendarFeedController::class, 'reset'])->name('calendar');
    Route::post('notifikasi', [PortalController::class, 'subscribePush'])->middleware('throttle:20,1')->name('push.subscribe');
    Route::delete('notifikasi', [PortalController::class, 'unsubscribePush'])->name('push.unsubscribe');
    Route::post('notifikasi/uji', [PortalController::class, 'testPush'])->middleware('throttle:5,1')->name('push.test');
});

// Mode petugas di browser: pindai QR Saya dengan kamera.
Route::middleware(['auth:web', 'scanner.web'])->prefix('pindai')->name('scanner.')->group(function () {
    Route::get('/', [ScannerController::class, 'index'])->name('index');
    Route::get('password', [ScannerController::class, 'password'])->name('password');
    Route::put('password', [ScannerController::class, 'updatePassword'])->middleware('throttle:10,1')->name('password.update');
    Route::get('{event}', [ScannerController::class, 'show'])->whereNumber('event')->name('show');
    Route::post('{event}/scan', [ScannerController::class, 'scan'])->whereNumber('event')->middleware('throttle:120,1')->name('scan');
    Route::get('{event}/foto/{member}', [Dashboard\EventMonitorController::class, 'photo'])->whereNumber(['event', 'member'])->name('photo');
});

// Iuran: Super Admin, bendahara, sekretaris (audit). Hak mengubah dicek di DuesService.
Route::middleware(['auth:web', 'finance:setup'])->prefix('iuran')->name('dues.')->group(function () {
    Route::get('keamanan', [Dashboard\DueController::class, 'security'])->name('security');
    Route::post('2fa', [Dashboard\AccountController::class, 'startTwoFactor'])->name('2fa.start');
    Route::post('2fa/konfirmasi', [Dashboard\AccountController::class, 'confirmTwoFactor'])->middleware('throttle:10,1')->name('2fa.confirm');
    Route::delete('2fa', [Dashboard\AccountController::class, 'disableTwoFactor'])->middleware('throttle:10,1')->name('2fa.disable');
});
Route::middleware(['auth:web', 'finance'])->prefix('iuran')->name('dues.')->group(function () {
    Route::get('/', [Dashboard\DueController::class, 'index'])->name('index');
    Route::post('/', [Dashboard\DueController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('{due}', [Dashboard\DueController::class, 'show'])->whereNumber('due')->name('show');
    Route::get('{due}/ekspor', [Dashboard\DueController::class, 'export'])->whereNumber('due')->middleware('throttle:20,1')->name('export');
    Route::post('{due}/tandai', [Dashboard\DueController::class, 'mark'])->whereNumber('due')->name('mark');
    Route::post('{due}/sinkron', [Dashboard\DueController::class, 'sync'])->whereNumber('due')->name('sync');
    Route::post('{due}/batal', [Dashboard\DueController::class, 'cancel'])->whereNumber('due')->name('cancel');
});

Route::middleware(['auth:web', 'dashboard'])->group(function () {
    Route::post('keluar', [Dashboard\AuthController::class, 'logout'])->name('logout');
    Route::get('/', Dashboard\HomeController::class)->name('home');
    Route::get('cari', Dashboard\SearchController::class)->middleware('throttle:60,1')->name('search');
    Route::get('akun', [Dashboard\AccountController::class, 'edit'])->name('account');
    // Notifikasi push dashboard (mis. pendaftar & laporan kartu baru) — endpoint sama dengan portal.
    Route::post('akun/notifikasi', [PortalController::class, 'subscribePush'])->middleware('throttle:20,1')->name('account.push.subscribe');
    Route::delete('akun/notifikasi', [PortalController::class, 'unsubscribePush'])->name('account.push.unsubscribe');
    Route::post('akun/notifikasi/uji', [PortalController::class, 'testPush'])->middleware('throttle:5,1')->name('account.push.test');
    Route::put('akun/ringkasan', [Dashboard\AccountController::class, 'weeklySummary'])->name('account.weekly-summary');
    Route::get('akun/ringkasan/contoh', [Dashboard\AccountController::class, 'weeklySummaryPreview'])->name('account.weekly-summary.preview');
    Route::post('akun/kalender', [CalendarFeedController::class, 'reset'])->name('account.calendar.reset');
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
    Route::post('kegiatan/{event}/tamu', [Dashboard\GuestController::class, 'store'])->middleware('throttle:30,1')->name('guests.store');
    Route::get('kegiatan/{event}/tamu/ekspor', [Dashboard\GuestController::class, 'export'])->middleware('throttle:20,1')->name('guests.export');
    Route::patch('kegiatan-tamu/{guest}', [Dashboard\GuestController::class, 'update'])->whereNumber('guest')->name('guests.update');
    Route::delete('kegiatan-tamu/{guest}', [Dashboard\GuestController::class, 'destroy'])->whereNumber('guest')->name('guests.destroy');
    Route::post('kegiatan/{event}/survei', [Dashboard\SurveyController::class, 'open'])->name('surveys.open');
    Route::post('kegiatan-survei/{survey}/tutup', [Dashboard\SurveyController::class, 'close'])->whereNumber('survey')->name('surveys.close');
    Route::put('kegiatan/{event}/notulen', [Dashboard\MinutesController::class, 'save'])->name('minutes.save');
    Route::post('kegiatan/{event}/notulen/terbit', [Dashboard\MinutesController::class, 'publish'])->name('minutes.publish');
    Route::post('kegiatan/{event}/dokumen', [Dashboard\MinutesController::class, 'storeDocument'])->middleware('throttle:60,1')->name('documents.store');
    Route::get('kegiatan-dokumen/{document}', [Dashboard\MinutesController::class, 'download'])->whereNumber('document')->name('documents.download');
    Route::patch('kegiatan-dokumen/{document}', [Dashboard\MinutesController::class, 'updateDocument'])->whereNumber('document')->name('documents.update');
    Route::delete('kegiatan-dokumen/{document}', [Dashboard\MinutesController::class, 'destroyDocument'])->whereNumber('document')->name('documents.destroy');
    Route::put('kegiatan/{event}/sertifikat', [Dashboard\CertificateController::class, 'issue'])->name('certificates.issue');
    Route::post('kegiatan-sertifikat/{certificate}/cabut', [Dashboard\CertificateController::class, 'revoke'])->name('certificates.revoke');
    Route::post('kegiatan-sertifikat/{certificate}/pulihkan', [Dashboard\CertificateController::class, 'restore'])->name('certificates.restore');
    Route::get('kegiatan-sertifikat/{certificate}.pdf', [Dashboard\CertificateController::class, 'pdf'])->whereNumber('certificate')->middleware('throttle:60,1')->name('certificates.pdf');
    Route::patch('absensi/{attendance}', [Dashboard\EventController::class, 'correct'])->name('attendances.correct');
    Route::get('izin', [Dashboard\ExcuseController::class, 'index'])->name('excuses.index');
    Route::post('izin', [Dashboard\ExcuseController::class, 'store'])->name('excuses.store');
    Route::post('izin/massal', [Dashboard\ExcuseController::class, 'reviewMany'])->name('excuses.review-many');
    Route::patch('izin/{excuse}', [Dashboard\ExcuseController::class, 'review'])->name('excuses.review');
    Route::get('izin/{excuse}/bukti', [Dashboard\ExcuseController::class, 'attachment'])->name('excuses.attachment');
    Route::resource('pengumuman', Dashboard\AnnouncementController::class)->parameters(['pengumuman' => 'announcement'])->names('announcements')->only(['index', 'store', 'update', 'destroy']);
    Route::get('tinjauan', [Dashboard\ReviewController::class, 'index'])->name('reviews.index');
    Route::post('tinjauan/tertahan/{scan}/terima', [Dashboard\ReviewController::class, 'acceptHeld'])->name('reviews.held.accept');
    Route::post('tinjauan/tertahan/{scan}/batalkan', [Dashboard\ReviewController::class, 'rejectHeld'])->name('reviews.held.reject');
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
        Route::post('anggota/{member}/jatah-kartu', [Dashboard\MemberController::class, 'grantSelfCard'])->name('members.self-card');
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
        Route::get('perubahan-data', [Dashboard\ProfileChangeController::class, 'index'])->name('profile-changes.index');
        Route::get('tugas', [Dashboard\DutyController::class, 'index'])->name('duties.index');
        Route::post('tugas/perpanjang', [Dashboard\DutyController::class, 'renew'])->name('duties.renew');
        Route::post('tugas/{duty}/akhiri', [Dashboard\DutyController::class, 'end'])->name('duties.end');
        Route::post('anggota/{member}/tugas', [Dashboard\DutyController::class, 'grant'])->name('duties.grant');
        Route::get('perubahan-data/{change}/foto', [Dashboard\ProfileChangeController::class, 'photo'])->name('profile-changes.photo');
        Route::post('perubahan-data/{change}', [Dashboard\ProfileChangeController::class, 'review'])->name('profile-changes.review');
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
