<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Stage extends Model
{
    use HasUuids;

    protected $guarded = [];
}
