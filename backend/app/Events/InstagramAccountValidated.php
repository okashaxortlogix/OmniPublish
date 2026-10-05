<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstagramAccountValidated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $publishJobId,
        public string $workspaceId,
        public string $socialAccountId,
        public string $igUserId,
        public string $accountType
    ) {}
}
