<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Member;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Pengguna & Peran (Super Admin). User tidak memakai global scope, jadi dibatasi eksplisit per organisasi. */
class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(Request $r)
    {
        $users = User::with(['division:id,name', 'member:id,member_no,name', 'financeTerm:id,is_current'])
            ->where('organization_id', $r->user()->organization_id)
            ->when($r->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->orderBy('name')->paginate(30)->withQueryString()
            ->through(fn (User $u) => $u->setAttribute('finance_active', $u->activeFinanceRole()));

        return Inertia::render('users/index', ['users' => $users, 'filters' => ['role' => $r->query('role')]]);
    }

    public function create()
    {
        return Inertia::render('users/form', ['user' => ['role' => User::SCANNER], 'divisions' => Division::orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $r)
    {
        $res = $this->users->create($r->user(), $this->input($r));

        return redirect()->route('users.index')
            ->with('status', "Akun {$res['user']->email} dibuat. Password sementara: {$res['password']} — sampaikan ke pemiliknya; tidak akan ditampilkan lagi.");
    }

    public function edit(Request $r, User $user)
    {
        $this->own($r, $user);

        $user->load('member:id,member_no');

        return Inertia::render('users/form', [
            'user' => $user->only('id', 'name', 'email', 'role', 'division_id') + ['member_no' => $user->member?->member_no, 'finance_role' => $user->activeFinanceRole()],
            'divisions' => Division::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $r, User $user)
    {
        $this->own($r, $user);
        $this->users->update($r->user(), $user, $this->input($r, $user));

        return redirect()->route('users.index')->with('status', 'Pengguna diperbarui.');
    }

    public function resetPassword(Request $r, User $user)
    {
        $this->own($r, $user);
        $password = $this->users->resetPassword($r->user(), $user);

        return back()->with('status', "Password {$user->email} direset: $password — sampaikan ke pemiliknya; tidak akan ditampilkan lagi.");
    }

    public function resetTwoFactor(Request $r, User $user, TwoFactorService $twoFactor)
    {
        $this->own($r, $user);
        abort_if($user->is($r->user()), 422, 'Kelola 2FA akun sendiri di Akun Saya.');
        $twoFactor->disable($user, $r->user());

        return back()->with('status', "2FA {$user->email} direset. Ia bisa login dengan password lalu mengaktifkan 2FA lagi.");
    }

    public function toggle(Request $r, User $user)
    {
        $this->own($r, $user);
        $user = $this->users->setDisabled($r->user(), $user, ! $user->disabled_at);

        return back()->with('status', $user->disabled_at ? 'Akun dinonaktifkan.' : 'Akun diaktifkan kembali.');
    }

    private function own(Request $r, User $user): void
    {
        abort_unless($user->organization_id === $r->user()->organization_id, 404);
    }

    private function input(Request $r, ?User $user = null): array
    {
        $d = $r->validate($this->users->rules($user) + ['member_no' => 'nullable|string|max:50']);
        $d['division_id'] = $d['division_id'] ?? null;
        $d['member_id'] = filled($d['member_no'] ?? null)
            ? (Member::where('member_no', $d['member_no'])->value('id') ?? abort(422, 'Nomor anggota tidak ditemukan.'))
            : null;
        unset($d['member_no']);

        return $d;
    }
}
