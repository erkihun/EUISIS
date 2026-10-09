<?php

declare(strict_types=1);

namespace App\Http\Resources\Transfers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferApplicationDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'original_name' => $this->original_name,
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'verification_status' => $this->verification_status?->value,
            'verification_remark' => $this->verification_remark,
            'verified_at' => $this->verified_at?->toISOString(),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy ? [
                'id' => $this->verifiedBy->id,
                'name' => $this->verifiedBy->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
