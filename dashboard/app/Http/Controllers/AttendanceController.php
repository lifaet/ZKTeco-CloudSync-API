<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index()
    {
        return view('dashboard');
    }

    public function data(Request $request)
    {
        // Support filtering by type (daily/monthly/user), date, month, user
        $type = $request->query('type', 'daily');
        $date = $request->query('date', null);
        $month = $request->query('month', null);
        $user = $request->query('user', null);

        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 10);

        if ($type === 'daily' && $date) {
            // --- Determine the last working day before the selected date (skips weekends & holidays) ---
            $weekendDays = Setting::getValue('weekend_days', [5, 6]); // 0=Sun ... 6=Sat; default Fri+Sat
            $holidays = Setting::getValue('holidays', []);
            $cursor = Carbon::parse($date)->subDay();
            $guard = 0;
            while ($guard++ < 60 && (in_array($cursor->dayOfWeek, $weekendDays) || in_array($cursor->toDateString(), $holidays))) {
                $cursor->subDay();
            }
            $lastWorkingDay = $cursor->toDateString();

            // --- Last punch of each user on the last working day ONLY ---
            // No fallback: if the user has no punch on that day, the column stays blank.
            $prevPunches = Attendance::whereDate('timestamp', $lastWorkingDay)
                ->orderBy('timestamp')
                ->get()
                ->groupBy('user_id')
                ->map(function ($records) {
                    return $records->last();
                });

            $buildPrev = function ($userId) use ($prevPunches) {
                $prev = $prevPunches->get($userId);
                if (! $prev) {
                    return ['prev_punch' => '', 'prev_date' => '', 'prev_stale' => false];
                }
                $ts = Carbon::parse($prev->timestamp);
                return [
                    'prev_punch' => $ts->format('H:i:s'),
                    'prev_date' => $ts->format('Y-m-d'),
                    'prev_stale' => false,
                ];
            };

            // Get all attendance records for the date
            $attendanceRecords = Attendance::whereDate('timestamp', $date)
                ->orderBy('timestamp')
                ->get();

            // Group by user_id
            $groupedRecords = $attendanceRecords->groupBy('user_id');
            $data = [];

            foreach ($groupedRecords as $userId => $records) {
                // Check user status
                $user = User::where('id', $userId)->first();
                if ($user && !$user->active) {
                    continue; // Skip inactive users
                }

                // Calculate times
                $first = $records->first();
                $last = $records->last();

                $inTime = Carbon::parse($first->timestamp)->format('H:i:s');
                $outTime = ($first->id !== $last->id) ? Carbon::parse($last->timestamp)->format('H:i:s') : '';
                $workTime = '';

                if ($outTime) {
                    $diff = Carbon::parse($last->timestamp)->diff(Carbon::parse($first->timestamp));
                    $workTime = sprintf('%02d:%02d:%02d', $diff->h, $diff->i, $diff->s ?? 0);
                }

                $data[] = array_merge([
                    'user_id' => $userId,
                    'name' => $user?->name ?? '',
                    'title' => $user?->title ?? '',
                    'department' => $user?->department ?? '',
                    'date' => $date,
                    'first_punch' => $inTime,
                    'last_punch' => $outTime,
                    'work_time' => $workTime,
                    'punch' => $last->punch ?? '',
                    'status' => $last->status ?? '',
                    'is_absent' => false,
                ], $buildPrev($userId));
            }

            // Now, add absent entries for active users with no attendance
            $activeUsers = User::where('active', true)->get();
            $attendedUserIds = $groupedRecords->keys()->toArray();

            foreach ($activeUsers as $user) {
                if (!in_array($user->id, $attendedUserIds)) {
                    $data[] = array_merge([
                        'user_id' => $user->id,
                        'name' => $user->name ?? '',
                        'title' => $user->title ?? '',
                        'department' => $user->department ?? '',
                        'date' => $date,
                        'first_punch' => 'Absent',
                        'last_punch' => '',
                        'work_time' => '',
                        'punch' => '',
                        'status' => '',
                        'is_absent' => true,
                    ], $buildPrev($user->id));
                }
            }

            // --- Tier 1: Department filter (server-side) ---
            if ($dept = $request->query('dept', $request->input('dept'))) {
                $deptLower = strtolower(trim($dept));
                if ($deptLower !== '' && $deptLower !== 'all') {
                    $data = array_filter($data, function($row) use ($deptLower) {
                        return strtolower($row['department'] ?? '') === $deptLower;
                    });
                    $data = array_values($data);
                }
            }

            // --- Flag: single status from first_punch with +-5 min grace around office_start ---
            $officeStart = Setting::getValue('office_start', '09:30');
            $officeEnd = Setting::getValue('office_end', '17:00');
            $grace = (int)Setting::getValue('office_grace', 5);
            [$oh, $om] = array_map('intval', explode(':', $officeStart));
            $officeMin = $oh * 60 + $om;
            foreach ($data as &$r) {
                $r['late_flag'] = false;
                $r['early_flag'] = false;
                $r['late_status'] = 'on_time';
                if (!$r['is_absent'] && $r['first_punch'] && $r['first_punch'] !== 'Absent') {
                    $fp = substr($r['first_punch'], 0, 5);
                    if (preg_match('/^\d{2}:\d{2}/', $fp)) {
                        [$fh, $fm] = array_map('intval', explode(':', $fp));
                        $fpMin = $fh * 60 + $fm;
                        $diff = $fpMin - $officeMin;
                        if ($diff > $grace) { $r['late_flag'] = true; $r['late_status'] = 'late'; }
                        elseif ($diff < -$grace) { $r['early_flag'] = true; $r['late_status'] = 'early'; }
                        else { $r['late_status'] = 'on_time'; }
                    }
                }
                if ($r['is_absent']) { $r['late_status'] = 'absent'; $r['late_flag'] = false; $r['early_flag'] = false; }
            }
            unset($r);

            // Apply search filter if provided — now checks Name/Title/Department as well (not just punches)
            if ($search = $request->input('search.value')) {
                $data = array_filter($data, function($row) use ($search) {
                    return stripos((string)($row['user_id'] ?? ''), $search) !== false ||
                           stripos((string)($row['name'] ?? ''), $search) !== false ||
                           stripos((string)($row['title'] ?? ''), $search) !== false ||
                           stripos((string)($row['department'] ?? ''), $search) !== false ||
                           stripos((string)($row['date'] ?? ''), $search) !== false ||
                           stripos((string)($row['first_punch'] ?? ''), $search) !== false ||
                           stripos((string)($row['last_punch'] ?? ''), $search) !== false ||
                           stripos((string)($row['work_time'] ?? ''), $search) !== false;
                });
            }

            $recordsTotal = count($data);
            $recordsFiltered = $recordsTotal;

            // --- Tier 1: Work Hours Summary Card (before paginate, after filters) ---
            $present = 0; $absent = 0; $late = 0; $totalWorkSec = 0; $workCount = 0;
            foreach ($data as $r) {
                if ($r['is_absent']) $absent++; else $present++;
                if (!empty($r['late_flag'])) $late++;
                if (!$r['is_absent'] && !empty($r['work_time'])) {
                    $parts = explode(':', $r['work_time']);
                    if (count($parts) >= 2) {
                        $sec = ((int)$parts[0]*3600) + ((int)$parts[1]*60) + ((int)($parts[2] ?? 0));
                        $totalWorkSec += $sec; $workCount++;
                    }
                }
            }
            $avgWork = $workCount ? gmdate('H:i', intdiv($totalWorkSec, $workCount)) : '—';
            $summary = ['present'=>$present,'absent'=>$absent,'late'=>$late,'avg_work'=>$avgWork,'office_start'=>$officeStart,'office_end'=>$officeEnd];

            // Sort by custom user view order (users without an order go last, sorted by user_id)
            $orderMap = User::whereNotNull('view_order')->pluck('view_order', 'id')->toArray();
            usort($data, function($a, $b) use ($orderMap) {
                $oa = $orderMap[$a['user_id']] ?? PHP_INT_MAX;
                $ob = $orderMap[$b['user_id']] ?? PHP_INT_MAX;
                if ($oa === $ob) {
                    return $a['user_id'] <=> $b['user_id'];
                }
                return $oa <=> $ob;
            });

            // Paginate
            $paged = array_slice($data, $start, $length);

            return response()->json([
                'draw' => intval($request->draw),
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $paged,
                'last_working_day' => $lastWorkingDay,
                'summary' => $summary,
            ]);
        }

        // Monthly / User: SQL-grouped (fixes full-table load + MySQL portability)
        $perPage = $length > 0 ? $length : 10;

        $base = DB::table('attendances')
            ->selectRaw("user_id, DATE(timestamp) as date, MIN(timestamp) as first_punch, CASE WHEN COUNT(*) > 1 THEN MAX(timestamp) ELSE NULL END as last_punch, CASE WHEN COUNT(*) > 1 THEN TIMESTAMPDIFF(SECOND, MIN(timestamp), MAX(timestamp)) ELSE NULL END as work_seconds, MAX(punch) as punch, MAX(status) as status")
            ->groupBy('user_id', DB::raw('DATE(timestamp)'));

        if ($type === 'monthly' && $month) {
            $base->whereRaw("DATE_FORMAT(timestamp, '%Y-%m') = ?", [$month]);
        }
        if ($type === 'user' && $user) {
            $base->where('user_id', $user);
        }

        $recordsTotal = DB::table(DB::raw("({$base->toSql()}) as t_total"))
            ->mergeBindings($base)->count();

        $filtered = clone $base;
        if ($search = $request->input('search.value')) {
            $filtered->having('user_id', 'like', "%{$search}%");
        }

        $recordsFiltered = DB::table(DB::raw("({$filtered->toSql()}) as t_filt"))
            ->mergeBindings($filtered)->count();

        $paged = DB::table(DB::raw("({$filtered->toSql()}) as t"))
            ->mergeBindings($filtered)
            ->orderBy('date', 'desc')->orderBy('user_id')
            ->offset($start)->limit($perPage)
            ->get()
            ->map(function($row){
                $first = $row->first_punch ? date('H:i:s', strtotime($row->first_punch)) : '';
                $last  = $row->last_punch ? date('H:i:s', strtotime($row->last_punch)) : '';
                $work = '';
                if ($row->work_seconds !== null) {
                    $h = intdiv($row->work_seconds, 3600);
                    $m = intdiv($row->work_seconds % 3600, 60);
                    $s = $row->work_seconds % 60;
                    $work = sprintf('%02d:%02d:%02d',$h,$m,$s);
                }
                return [
                    'user_id' => $row->user_id,
                    'date' => $row->date,
                    'first_punch' => $first,
                    'last_punch' => $last,
                    'work_time' => $work,
                    'punch' => $row->punch ?? '',
                    'status' => $row->status ?? '',
                    'is_absent' => false,
                ];
            });

        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $paged,
        ]);
    }

    public function latest(Request $request)
    {

        $lastId = (int) $request->get('last_id', 0);

        // If a last_id is provided, return the next new entry after that id (oldest new entry)
        if ($lastId > 0) {
            $newEntry = Attendance::where('id', '>', $lastId)
                ->orderBy('id', 'asc')
                ->first(['id', 'user_id', 'timestamp']);

            // If a last_id was provided but no newer entry found, return 204 No Content
            if (! $newEntry) {
                return response()->json(null, 204);
            }
        } else {
            // No last_id provided -> return the most recent attendance (for initial sync)
            $newEntry = Attendance::orderBy('id', 'desc')->first(['id', 'user_id', 'timestamp']);
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

    public function update(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|string',
            'date' => 'required|date_format:Y-m-d',
            'first_punch' => 'required|date_format:H:i:s',
            'last_punch' => 'nullable|date_format:H:i:s',
            'punch' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:50',
        ]);
        $userId = $data['user_id'];
        $date = $data['date'];

        // Find the attendance records for this user and date
        $records = Attendance::where('user_id', $userId)
            ->whereDate('timestamp', $date)
            ->orderBy('timestamp')
            ->get();

        if ($records->isEmpty()) {
            return response()->json(['message' => 'Records not found'], 404);
        }

        // Update the first and last records
        $firstRecord = $records->first();
        $lastRecord = $records->last();

        // Parse the time inputs
        $firstTime = Carbon::parse($date . ' ' . $data['first_punch']);

        // Update first punch
        $firstRecord->timestamp = $firstTime;
        $firstRecord->punch = $data['punch'] ?? $firstRecord->punch;
        $firstRecord->status = $data['status'] ?? $firstRecord->status;
        $firstRecord->save();

        // If there's a last punch and it's different from first punch
        if (!empty($data['last_punch']) && $firstRecord->id !== $lastRecord->id) {
            $lastTime = Carbon::parse($date . ' ' . $data['last_punch']);
            $lastRecord->timestamp = $lastTime;
            $lastRecord->punch = $data['punch'] ?? $lastRecord->punch;
            $lastRecord->status = $data['status'] ?? $lastRecord->status;
            $lastRecord->save();
        }

        return response()->json(['message' => 'Records updated successfully']);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|string',
            'date' => 'required|date_format:Y-m-d',
        ]);
        $userId = $data['user_id'];
        $date = $data['date'];

        // Delete all attendance records for this user and date
        $deleted = Attendance::where('user_id', $userId)
            ->whereDate('timestamp', $date)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Records not found'], 404);
        }

        return response()->json(['message' => 'Records deleted successfully']);
    }

    /**
     * Add a manual attendance record (first punch required, optional last punch).
     */
    public function add(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|string',
            'date' => 'required|date_format:Y-m-d',
            'first_punch' => 'required|date_format:H:i:s',
            'last_punch' => 'nullable|date_format:H:i:s',
            'status' => 'nullable|string|max:50',
        ]);
        $userId = $data['user_id'];
        $date = $data['date'];
        $firstPunch = $data['first_punch'];
        $lastPunch = $data['last_punch'] ?? null;
        $status = $data['status'] ?? null;

        // Prevent 3+ rows per user per day — manual Add is for absent/single-punch days only (max 2)
        $existingCount = Attendance::where('user_id', $userId)->whereDate('timestamp', $date)->count();
        $newRows = ($lastPunch && $lastPunch !== $firstPunch) ? 2 : 1;
        if ($existingCount + $newRows > 2) {
            return response()->json(['message' => 'This user already has '.$existingCount.' punch(es) on '.$date.'. Maximum 2 per day — use Edit instead.'], 422);
        }
        if ($existingCount > 0) {
            // Optional: ensure user exists and is active
            $u = User::find($userId);
            if ($u && !$u->active) {
                return response()->json(['message' => 'User is inactive'], 422);
            }
        }

        try {
            $firstTs = Carbon::parse($date . ' ' . $firstPunch)->format('Y-m-d H:i:s');

            Attendance::create([
                'user_id' => $userId,
                'timestamp' => $firstTs,
                'status' => $status,
                'punch' => 'manual',
                'message' => 'Created manually via dashboard'
            ]);

            if ($lastPunch && $lastPunch !== $firstPunch) {
                $lastTs = Carbon::parse($date . ' ' . $lastPunch)->format('Y-m-d H:i:s');
                Attendance::create([
                    'user_id' => $userId,
                    'timestamp' => $lastTs,
                    'status' => $status,
                    'punch' => 'manual',
                    'message' => 'Created manually via dashboard'
                ]);
            }

            return response()->json(['message' => 'Attendance created successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create attendance: ' . $e->getMessage()], 500);
        }
    }

    // Return distinct users for frontend dropdowns
    public function users()
    {
        $users = Attendance::select('user_id')
            ->distinct()
            ->orderBy('user_id')
            ->get()
            ->map(function($u){ return ['id' => $u->user_id]; });

        return response()->json($users);
    }
}
