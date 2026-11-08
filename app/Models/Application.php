<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Application extends Model
{
    protected $fillable = [
        'status', 'to_email', 'cc', 'subject', 'body', 'source_draft_id', 'validated_at', 'sent_at',
        'gmail_message_id', 'gmail_thread_id', 'drive_folder_url', 'last_error',
    ];

    protected $attributes = [
        'status' => 'brouillon',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'validated_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Application $application) {
            $application->attachments->each->delete();
        });
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ApplicationAttachment::class);
    }

    public function isSent(): bool
    {
        return $this->status === ApplicationStatus::Sent;
    }

    /** Ce qui manque encore pour pouvoir valider l'envoi. */
    public function missingForSending(): array
    {
        $missing = [];

        if (blank($this->to_email) || ! filter_var($this->to_email, FILTER_VALIDATE_EMAIL)) {
            $missing[] = 'une adresse de destinataire valide';
        }
        if (blank($this->subject)) {
            $missing[] = 'un objet';
        }
        if (blank($this->body)) {
            $missing[] = 'un message';
        }

        return $missing;
    }

    /** @return list<string> */
    public function ccList(): array
    {
        return collect(preg_split('/[,;\s]+/', (string) $this->cc))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
    }

    public static function attachmentDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('local');
    }
}
