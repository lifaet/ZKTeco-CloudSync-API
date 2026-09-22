<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attendance2;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class Attendance2Controller extends Controller
{
    public function index()
    {
        // Require simple session-based auth
        if (! session('dashboard_logged_in')) {
            return redirect('/login');
        }

        return view('attendance2');
    }

    public function data(Request $request)
    {
        // Require simple session-based auth
        if (! session('dashboard_logged_in')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 10);

        $query = Attendance2::query();

        // Apply search filter if provided
        if ($search = $request->input('search.value')) {
            $query->where(function($q) use ($search) {
                $q->where('user_id', 'like', '%' . $search . '%')
                  ->orWhere('status', 'like', '%' . $search . '%')
                  ->orWhere('punch', 'like', '%' . $search . '%');
            });
        }

        $recordsTotal = $query->count();
        $recordsFiltered = $recordsTotal;

        $data = $query->orderBy('timestamp', 'desc')
                      ->skip($start)
                      ->take($length)
                      ->get()
                      ->map(function($record) {
                          $user = User::where('id', $record->user_id)->first();
                          return [
                              'id' => $record->id,
                              'user_id' => $record->user_id,
                              'name' => $user ? $user->name : '',
                              'title' => $user ? $user->title : '',
                              'department' => $user ? $user->department : '',
                              'timestamp' => Carbon::parse($record->timestamp)->format('Y-m-d H:i:s'),
                              'status' => $record->status ?? '',
                              'punch' => $record->punch ?? '',
                          ];
                      });

        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function summary(Request $request)
    {
        if (! session('dashboard_logged_in')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $today = Carbon::today()->toDateString();
        $countToday = Attendance2::whereDate('timestamp', $today)->count();
        $lastFive = Attendance2::orderBy('timestamp', 'desc')->take(5)->get()->map(function($r){
            $user = User::where('id', $r->user_id)->first();
            return [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'name' => $user ? $user->name : '',
                'timestamp' => Carbon::parse($r->timestamp)->format('Y-m-d H:i:s'),
                'time' => Carbon::parse($r->timestamp)->format('H:i:s'),
            ];
        });
        return response()->json(['count' => $countToday, 'recordsTotal' => $countToday, 'last_five' => $lastFive]);
    }

    public function latest(Request $request)
    {
        $lastId = (int) $request->get('last_id', 0);

        // If a last_id is provided, return the next new entry after that id (oldest new entry)
        if ($lastId > 0) {
            $newEntry = Attendance2::where('id', '>', $lastId)
                ->orderBy('id', 'asc')
                ->first(['id', 'user_id', 'timestamp']);

            // If a last_id was provided but no newer entry found, return 204 No Content
            if (! $newEntry) {
                return response()->json(null, 204);
            }
        } else {
            // No last_id provided -> return the most recent attendance (for initial sync)
            $newEntry = Attendance2::orderBy('id', 'desc')->first(['id', 'user_id', 'timestamp']);
        }

        if (! $newEntry) {
            return response()->json(null);
        }

        $ts = Carbon::parse($newEntry->timestamp);

        return response()->json([
            'id' => $newEntry->id,
            'user_id' => $newEntry->user_id,
            'time' => $ts->format('H:i:s'),
            'date' => $ts->format('Y-m-d'),
        ]);
    }
}
