<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'platform',
        'platform_account_id',
        'account_name',
        'account_handle',
        'avatar_url',
        'status',
        'scopes',
        'encrypted_access_token',
        'encrypted_refresh_token',
        'token_expires_at',
        'metadata',
    ];

    protected $hidden = [
        'encrypted_access_token',
        'encrypted_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'metadata' => 'array',
            'token_expires_at' => 'datetime',
            'encrypted_access_token' => 'encrypted',
            'encrypted_refresh_token' => 'encrypted',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(PublishJob::class);
    }
}
