<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LogController extends Controller
{
    public function index(Request $r)
    {
        $orgUsers = User::where('organization_id', $r->user()->organization_id)->orderBy('name')->get(['id', 'name']);
        $logs = AuditLog::query()->whereIn('user_id', $orgUsers->pluck('id'))
            ->when($r->query('action'), fn ($q, $a) => $q->where('action', 'like', "$a%"))
            ->when($r->query('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($r->query('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($r->query('to'), fn ($q, $d) => $q->where('created_at', '<', \Illuminate\Support\Carbon::parse($d)->addDay()))
            ->latest('created_at')->paginate(50)->withQueryString();

        return Inertia::render('logs/index', [
            'logs' => $logs, 'users' => $orgUsers->pluck('name', 'id'),
            'filters' => $r->only('action', 'user_id', 'from', 'to') + ['action' => null, 'user_id' => null, 'from' => null, 'to' => null],
        ]);
    }
}
