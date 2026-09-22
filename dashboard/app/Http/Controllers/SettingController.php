<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;

class SettingController extends Controller
{
    // GET /api/settings
    public function index()
    {
        return response()->json([
            // Day numbers: 0=Sunday ... 6=Saturday. Default weekend: Friday & Saturday.
            'weekend_days' => Setting::getValue('weekend_days', [5, 6]),
            // Holidays as list of YYYY-MM-DD strings
            'holidays' => Setting::getValue('holidays', []),
            'office_start' => Setting::getValue('office_start', '09:30'),
            'office_end' => Setting::getValue('office_end', '17:00'),
            'office_grace' => Setting::getValue('office_grace', 5),
        ]);
    }

    // POST /api/settings
    public function update(Request $request)
    {
        $data = $request->validate([
            'weekend_days' => 'sometimes|array',
            'weekend_days.*' => 'integer|min:0|max:6',
            'holidays' => 'sometimes|array',
            'holidays.*' => 'date_format:Y-m-d',
            'office_start' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'office_end' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'office_grace' => 'sometimes|integer|min:0|max:60',
        ]);

        if (array_key_exists('weekend_days', $data)) {
            Setting::setValue('weekend_days', array_values(array_unique($data['weekend_days'])));
        }
        if (array_key_exists('holidays', $data)) {
            $holidays = array_values(array_unique($data['holidays']));
            sort($holidays);
            Setting::setValue('holidays', $holidays);
        }
        if (array_key_exists('office_start', $data)) {
            Setting::setValue('office_start', $data['office_start']);
        }
        if (array_key_exists('office_end', $data)) {
            Setting::setValue('office_end', $data['office_end']);
        }
        if (array_key_exists('office_grace', $data)) {
            Setting::setValue('office_grace', (int)$data['office_grace']);
        }

        return $this->index();
    }
}
