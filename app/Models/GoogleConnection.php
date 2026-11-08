<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Connexion OAuth au compte Google du propriétaire (une seule ligne).
 */
class GoogleConnection extends Model
{
    protected $fillable = ['email', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'drive_root_folder_id',
        'registry_synced_at', 'registry_sync_summary', 'registry_sync_error'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'registry_synced_at' => 'datetime',
        ];
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, explode(' ', (string) $this->scopes), true);
    }

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
