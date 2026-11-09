<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationAttachment extends Model
{
    protected $fillable = ['name', 'path', 'mime', 'size', 'drive_file_url', 'source_hash'];

    protected static function booted(): void
    {
        static::deleting(function (ApplicationAttachment $attachment) {
            Application::attachmentDisk()->delete($attachment->path);
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf' || str_ends_with(mb_strtolower($this->name), '.pdf');
    }

    public function contents(): string
    {
        return (string) Application::attachmentDisk()->get($this->path);
    }
}
