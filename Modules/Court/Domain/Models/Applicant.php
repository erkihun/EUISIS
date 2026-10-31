<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Applicant extends Authenticatable implements MustVerifyEmailContract
{
    protected $table = 'court_applicants';

    use HasApiTokens, Notifiable, MustVerifyEmailTrait;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'position',
        'organization_name',
        'phone',
        'email',
        'address',
        'national_id_number',
        'password',
        'is_active',
        'is_lawyer',
        'lawyer_document_path',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_lawyer' => 'boolean',
            'lawyer_verified_at' => 'datetime',
            'lawyer_applied_at' => 'datetime',
            'lawyer_refused_at' => 'datetime',
        ];
    }

    /**
     * Store National ID as 16 digits; present as "0000 0000 0000 0000".
     */
    protected function nationalIdNumber(): Attribute
    {
        return Attribute::make(
            set: fn($value) => preg_replace('/\D/', '', (string) $value), // keep digits only
            get: fn($value) => $value
                ? trim(chunk_split(preg_replace('/\D/', '', (string) $value), 4, ' '))
                : ''
        );
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    /** Send custom verification email. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(app(\Modules\Court\Domain\Models\Concerns\ApplicantMessages::class)->verifyEmail());
    }

    /** Send custom password reset email. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(app(\Modules\Court\Domain\Models\Concerns\ApplicantMessages::class)->resetPassword((string) $token));
    }
}
