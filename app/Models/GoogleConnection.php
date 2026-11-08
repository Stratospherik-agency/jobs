<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Connexion OAuth au compte Google du propriétaire (une seule ligne).
 */
class GoogleConnection extends Model
{
    protected $fillable = ['email', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'drive_root_folder_id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
