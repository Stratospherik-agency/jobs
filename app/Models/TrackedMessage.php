<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message Gmail déjà analysé (réponse d'un recruteur ou signal de veille).
 */
class TrackedMessage extends Model
{
    protected $fillable = ['gmail_message_id', 'purpose', 'kind', 'opportunity_id', 'prospect_id', 'subject', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public static function seen(string $id, string $purpose): bool
    {
        return static::query()->where('gmail_message_id', $id)->where('purpose', $purpose)->exists();
    }
}
