<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class CaseType extends Model
{
    protected $table = 'court_case_types';
    protected $guarded = [];
    public $timestamps = false;
}
