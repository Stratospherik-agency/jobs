<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglage modifiable depuis la page Réglages. La valeur est toujours chiffrée en base.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
