<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function index()
    {
        if (! session('dashboard_logged_in')) {
            return redirect('/login');
        }
        return view('analytics');
    }

    public function data(Request $request)
    {
        if (! session('dashboard_logged_in')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $month = $request->query('month', Carbon::now()->format('Y-m'));

        // 1. Monthly present % (per day in month)
        $weekendDays = Setting::getValue('weekend_days', [5, 6]);
        $holidays = Setting::getValue('holidays', []);
        $start = Carbon::parse($month . '-01');
        $end = $start->copy()->endOfMonth();
        $activeCount = User::where('active', true)->count();
        $todayStr = Carbon::today()->toDateString();
        $dailyPresent = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $isWeekend = in_array($cursor->dayOfWeek, $weekendDays);
            $isHoliday = in_array($dateStr, $holidays);
            $isFuture = $dateStr > $todayStr;
            if ($isWeekend || $isHoliday || $isFuture) {
                $dailyPresent[] = ['date' => $dateStr, 'pct' => null];
            } else {
                $present = DB::table('attendances')->whereDate('timestamp', $dateStr)->select('user_id')->distinct()->count();
                $pct = $activeCount ? round(min(100, $present / $activeCount * 100), 1) : 0;
                $dailyPresent[] = ['date' => $dateStr, 'pct' => $pct, 'present' => $present, 'total' => $activeCount];
            }
            $cursor->addDay();
        }

        // 2. Dept avg hours (for selected month)
        $officeStart = Setting::getValue('office_start', '09:30');
        $officeEnd = Setting::getValue('office_end', '17:00');
        $deptHours = DB::table('attendances')
            ->whereRaw("DATE_FORMAT(timestamp, '%Y-%m') = ?", [$month])
            ->selectRaw("user_id, DATE(timestamp) as d, MIN(timestamp) as f, MAX(timestamp) as l, TIMESTAMPDIFF(SECOND, MIN(timestamp), MAX(timestamp)) as sec")
            ->groupBy('user_id', DB::raw('DATE(timestamp)'))
            ->havingRaw("COUNT(*) > 1")
            ->get();
        $deptMap = [];
        foreach ($deptHours as $r) {
            $user = User::find($r->user_id);
            $dept = $user ? ($user->department ?: 'Unknown') : 'Unknown';
            if (!isset($deptMap[$dept])) $deptMap[$dept] = ['total' => 0, 'count' => 0];
            $deptMap[$dept]['total'] += $r->sec;
            $deptMap[$dept]['count'] += 1;
        }
        $deptAvg = [];
        foreach ($deptMap as $dept => $v) {
            $avgSec = $v['count'] ? intdiv($v['total'], $v['count']) : 0;
            $h = intdiv($avgSec, 3600);
            $m = intdiv($avgSec % 3600, 60);
            $deptAvg[] = ['dept' => $dept, 'avg_hours' => sprintf('%02d:%02d', $h, $m), 'avg_sec' => $avgSec, 'count' => $v['count']];
        }
        usort($deptAvg, fn($a,$b) => $b['avg_sec'] <=> $a['avg_sec']);

        // 3. Late trend (last 30 days) - +- grace on first punch only
        $grace = (int)Setting::getValue('office_grace', 5);
        [$oh, $om] = array_map('intval', explode(':', $officeStart));
        $officeMin = $oh * 60 + $om;
        $lateTrend = [];
        $cursor = Carbon::today()->subDays(29);
        for ($i=0; $i<30; $i++) {
            $date = $cursor->toDateString();
            $isWeekend = in_array($cursor->dayOfWeek, $weekendDays);
            $isHoliday = in_array($date, $holidays);
            if ($isWeekend || $isHoliday) {
                $lateTrend[] = ['date' => $date, 'late' => null];
            } else {
                $rows = Attendance::whereDate('timestamp', $date)->orderBy('timestamp')->get()->groupBy('user_id');
                $late = 0;
                foreach ($rows as $uid => $recs) {
                    $user = User::where('id', $uid)->first();
                    if ($user && !$user->active) continue;
                    $first = Carbon::parse($recs->first()->timestamp)->format('H:i');
                    if (!preg_match('/^\d{2}:\d{2}/', $first)) continue;
                    [$fh, $fm] = array_map('intval', explode(':', $first));
                    $fpMin = $fh * 60 + $fm;
                    if (($fpMin - $officeMin) > $grace) $late++;
                }
                $lateTrend[] = ['date' => $date, 'late' => $late];
            }
            $cursor->addDay();
        }

        return response()->json([
            'month' => $month,
            'daily_present' => $dailyPresent,
            'dept_avg' => $deptAvg,
            'late_trend' => $lateTrend,
            'office_start' => $officeStart,
            'office_end' => $officeEnd,
        ]);
    }
}
