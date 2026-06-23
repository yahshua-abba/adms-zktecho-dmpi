<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $level = $request->query('level');

        $logs = ActivityLog::query()
            ->when($level, fn ($q) => $q->where('level', $level))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('activity.index', ['logs' => $logs, 'level' => $level]);
    }
}
