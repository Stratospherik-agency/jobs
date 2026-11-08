<?php

namespace App\Models;

use App\Enums\OpportunityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityEvent extends Model
{
    protected $fillable = ['type', 'from_status', 'to_status', 'body', 'happened_at'];

    protected function casts(): array
    {
        return [
            'from_status' => OpportunityStatus::class,
            'to_status' => OpportunityStatus::class,
            'happened_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /** Résumé lisible de l'événement. */
    public function summary(): string
    {
        if ($this->type === 'statut' && $this->from_status) {
            return $this->from_status->getLabel().' → '.$this->to_status?->getLabel();
        }

        if ($this->type === 'statut') {
            return trim(($this->body ?? '').' ('.$this->to_status?->getLabel().')');
        }

        return (string) $this->body;
    }
}
