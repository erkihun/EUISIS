<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class CardVerification extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'id_card_id',
        'service_type_id',
        'service_provider_id',
        'api_client_id',
        'device_binding_id',
        'result_code',
        'allowed',
        'request_ip',
        'request_user_agent',
        'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'allowed' => 'bool',
            'response_payload' => 'array',
        ];
    }
}
