<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendances';
    protected $fillable = [
        'user_id',
        'timestamp',
        'status',
        'punch',
        'message',
    ];
    public $timestamps = true;
}
