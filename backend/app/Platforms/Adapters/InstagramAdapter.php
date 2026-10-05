<?php

namespace App\Platforms\Adapters;

/**
 * InstagramAdapter connects OmniPublish to the official Meta / Instagram Content Publishing API.
 * Delegates all publishing, validation, polling, and error normalization to InstagramPublisher.
 */
class InstagramAdapter extends InstagramPublisher
{
    // Inherits complete production Graph API v21.0 implementation from InstagramPublisher
}
