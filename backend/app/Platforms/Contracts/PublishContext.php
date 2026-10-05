<?php

namespace App\Platforms\Contracts;

use App\Models\SocialAccount;
use App\Models\MediaAsset;
use App\Models\PublishJob;

class PublishContext
{
    public function __construct(
        public readonly PublishJob $job,
        public readonly SocialAccount $account,
        public readonly ?MediaAsset $media = null,
        public readonly array $globalMetadata = [],
        public readonly array $platformOverrides = [],
        public readonly string $decryptedAccessToken = ''
    ) {}
}
