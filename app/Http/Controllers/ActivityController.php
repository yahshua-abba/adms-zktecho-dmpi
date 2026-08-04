<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\PerPage;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $level = $request->query('level');
        $perPage = PerPage::resolve($request->has('per_page') ? (int) $request->query('per_page') : null);

        $logs = ActivityLog::query()
            ->when($level, fn ($q) => $q->where('level', $level))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return view('activity.index', ['logs' => $logs, 'level' => $level]);
    }
}
