<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\CourtStaffMember as User;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DecisionReview extends Model
{
    protected $table = 'court_decision_reviews';

    use HasFactory;

    protected $fillable = [
        'decision_id',
        'case_number',
        'reviewer_id',
        'review_note',
        'outcome',
    ];

    public function decision()
    {
        return $this->belongsTo(Decision::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
