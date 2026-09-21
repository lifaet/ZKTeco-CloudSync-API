<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use Illuminate\Support\Facades\Redirect;

// Dashboard auth now via middleware + config/dashboard.php — exports run client-side; keep controller focused on DB/views


class DashboardController extends Controller
{
    public function index()
    {
        // Require simple session-based auth
        if (! session('dashboard_logged_in')) {
            return redirect('/login');
        }

        return view('dashboard');
    }

    // Show login form
    public function showLogin()
    {
        return view('login');
    }

    // Process login — uses bcrypt hash if DASHBOARD_PASSWORD_HASH is set, fallback to plain for dev
    public function login(Request $request)
    {
        $password = $request->input('password');
        $hash = config('dashboard.password_hash');
        if ($hash && Hash::check($password, $hash)) {
            $request->session()->regenerate();
            session(['dashboard_logged_in' => true]);
            return redirect('/');
        }
        // Dev fallback when no hash configured
        $plain = config('dashboard.password_plain', 'secret');
        if (! $hash && $password === $plain) {
            $request->session()->regenerate();
            session(['dashboard_logged_in' => true]);
            return redirect('/');
        }
        return back()->with('login_error', 'Invalid password');
    }

    // Logout
    public function logout(Request $request)
    {
        $request->session()->forget('dashboard_logged_in');
        return redirect('/login');
    }

    public function getServerSideData(Request $request)
    {
        // ensure authenticated for AJAX data
        if (! session('dashboard_logged_in')) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $columns = ['user_id', 'date', 'first_punch', 'last_punch', 'work_time'];
        $length = $request->input('length', 10);
        $start = $request->input('start', 0);
        $search = $request->input('search.value', '');
        $orderColumn = $columns[$request->input('order.0.column', 1)] ?? 'date';
        $orderDir = $request->input('order.0.dir', 'desc');

        $type = $request->query('type', 'daily');
        $date = $request->query('date', null);
        $month = $request->query('month', null);
        $user = $request->query('user', null);

        // Base grouped query
        $sub = DB::table('attendances')
            ->select(
                'user_id',
                DB::raw('DATE(timestamp) as date'),
                DB::raw('MIN(timestamp) as first_punch'),
                DB::raw('CASE WHEN COUNT(*) > 1 THEN MAX(timestamp) ELSE NULL END as last_punch'),
                DB::raw('CASE WHEN COUNT(*) > 1 THEN (TIMESTAMPDIFF(SECOND, MIN(timestamp), MAX(timestamp))) ELSE NULL END as work_seconds')
            )
            ->groupBy('user_id', DB::raw('DATE(timestamp)'));

        // Filters
        if ($type === 'daily' && $date) $sub->whereDate('timestamp', $date);
        if ($type === 'monthly' && $month) $sub->whereRaw("DATE_FORMAT(timestamp, '%Y-%m') = ?", [$month]);
        if ($type === 'user' && $user) $sub->where('user_id', $user);

        // Wrap for pagination
        $query = DB::table(DB::raw("({$sub->toSql()}) as t"))->mergeBindings($sub);

        // Apply search
        if ($search) $query->where('user_id', 'like', "%$search%");

        // Total records
        $recordsTotal = DB::table(DB::raw("({$sub->toSql()}) as t_total"))->mergeBindings($sub)->count();

        // Paginate
        $data = $query->orderBy($orderColumn, $orderDir)
                      ->offset($start)
                      ->limit($length)
                      ->get()
                      ->map(function($item){
                          $item->first_punch = $item->first_punch ? date('H:i:s', strtotime($item->first_punch)) : '';
                          $item->last_punch = $item->last_punch ? date('H:i:s', strtotime($item->last_punch)) : '';
                          if ($item->work_seconds) {
                              $hours = floor($item->work_seconds / 3600);
                              $minutes = floor(($item->work_seconds % 3600) / 60);
                              $seconds = $item->work_seconds % 60;
                              $item->work_time = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                          } else {
                              $item->work_time = '';
                          }
                          return $item;
                      });

        return response()->json([
            "draw" => intval($request->input('draw')),
            "recordsTotal" => $recordsTotal,
            "recordsFiltered" => $recordsTotal,
            "data" => $data,
        ]);
    }





}