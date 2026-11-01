<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\CourtStaffMember as User;

class Decision extends Model
{
    protected $table = 'court_decisions';

    use HasFactory;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'court_case_id',
        'case_number',
        'case_file_number',
        'applicant_full_name',
        'respondent_full_name',
        'case_filed_date',
        'decision_date',
        'panel_judges',
        'panel_decision',
        'judges_comments',
        'reviewing_admin_user_id',
        'reviewing_admin_user_name',
        'reviewing_admin_user_names',
        'name',
        'description',
        'decision_content',
        'status',
        'approved_at',
        'approved_by',
    ];

    /**
     * Attribute casts.
     */
    protected $casts = [
        'case_filed_date' => 'date',
        'decision_date' => 'date',
        'panel_judges' => 'array',
        'reviewing_admin_user_names' => 'array',
        'approved_at' => 'datetime',
    ];

    public function courtCase(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'court_case_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewing_admin_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reviews()
    {
        return $this->hasMany(DecisionReview::class);
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * The presiding (session) judge occupies the middle slot of the panel.
     */
    public function presidingJudgeId(): ?int
    {
        $id = (int) ($this->panel_judges[1]['admin_user_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * A decision is attributable only when all three panel seats are held by
     * three different judges.
     */
    public function hasCompletePanel(): bool
    {
        $ids = collect(is_array($this->panel_judges) ? array_values($this->panel_judges) : [])
            ->take(3)
            ->map(fn ($judge): int => (int) (is_array($judge) ? ($judge['admin_user_id'] ?? 0) : 0))
            ->filter(fn (int $id): bool => $id > 0);

        return $ids->count() === 3 && $ids->unique()->count() === 3;
    }

    /**
     * Return the judicial panel with the recorded decision author first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panelJudgesAuthorFirst(): array
    {
        $panel = is_array($this->panel_judges) ? array_values($this->panel_judges) : [];
        $authorId = (int) $this->reviewing_admin_user_id;

        if (! $authorId) {
            return $panel;
        }

        return collect($panel)
            ->sortByDesc(
                fn (array $judge): bool => (int) ($judge['admin_user_id'] ?? 0) === $authorId,
            )
            ->values()
            ->all();
    }

    /**
     * Parties (applicant/respondent) may download the decision only once it is
     * both approved (sealed) and published.
     */
    public function isDownloadableByParties(): bool
    {
        return $this->isApproved() && $this->isPublished();
    }
}
