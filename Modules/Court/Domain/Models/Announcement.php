<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $table = 'court_announcements';

    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'status',
    ];
}
