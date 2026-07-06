<?php

namespace App\Modules\Inbox\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InternalNote extends Model
{
    use HasUuids;

    protected $guarded = [];
}
