<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\CourtStaffMember as User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenchNote extends Model
{
    protected $table = 'court_bench_notes';

    protected $fillable = [
        'case_id',
        'hearing_id',
        'user_id',
        'title',
        'note_date',
        'note',
        'judge_one_id',
        'judge_two_id',
        'judge_three_id',
    ];

    protected $casts = [
        'note_date' => 'date',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(CaseHearing::class, 'hearing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function judgeOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judge_one_id');
    }

    public function judgeTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judge_two_id');
    }

    public function judgeThree(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judge_three_id');
    }
}
