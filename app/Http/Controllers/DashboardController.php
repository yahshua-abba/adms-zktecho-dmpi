<?php

namespace App\Http\Controllers;

use App\Queries\DashboardStats;

class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard', ['stats' => DashboardStats::summary()]);
    }
}
