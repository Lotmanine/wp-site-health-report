<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    use HasUuids;

    protected $fillable = ['scan_ref', 'url', 'status', 'result', 'error'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
