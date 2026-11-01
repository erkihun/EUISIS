<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class LetterCategory extends Model
{
    protected $table = 'court_letter_categories';

    protected $fillable = [
        'name',
        'description',
    ];
}
