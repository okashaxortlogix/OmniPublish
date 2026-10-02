import {
  SupportedPlatform,
  PlatformCapabilities,
  GlobalContentMetadata,
  PlatformSpecificOverrides,
  MediaAsset,
  SocialAccount,
  FailureCategory
} from '@omnipublish/types';

// 1. Verified Official Capabilities for MVP Platforms

export const YOUTUBE_CAPABILITIES: PlatformCapabilities = {
  platform: 'YOUTUBE',
  supportsTitle: true,
  supportsDescription: true,
  supportsCaption: false,
  supportsTags: true,
  supportsHashtags: true,
  supportsCustomThumbnail: true,
  supportsNativeScheduling: true,
  supportsAnalytics: true,
  requiresCategory: true,
  limits: {
    maxTitleLength: 100,
    maxDescriptionLength: 5000,
    maxTagsLength: 500,
    maxVideoDurationSeconds: 43200, // 12 hours (unverified accounts: 15 min)
    minVideoDurationSeconds: 1,
    maxFileSizeMB: 256000, // 256 GB
    supportedAspectRatios: ['16:9', '9:16', '1:1', '4:3']
  }
};

export const TIKTOK_CAPABILITIES: PlatformCapabilities = {
  platform: 'TIKTOK',
  supportsTitle: false, // Combined into caption
  supportsDescription: false,
  supportsCaption: true, // post_info.title
  supportsTags: false, // tags must be hashtags
  supportsHashtags: true,
  supportsCustomThumbnail: false, // video cover timestamp offset instead
  supportsNativeScheduling: false, // Dispatched via OmniPublish scheduler
  supportsAnalytics: true,
  requiresCategory: false,
  limits: {
    maxCaptionLength: 2200,
    maxHashtagsCount: 30,
    maxVideoDurationSeconds: 600, // 10 minutes
    minVideoDurationSeconds: 3,
    maxFileSizeMB: 1000, // 1 GB direct upload
    supportedAspectRatios: ['9:16', '1:1', '16:9']
  }
};

export const INSTAGRAM_CAPABILITIES: PlatformCapabilities = {
  platform: 'INSTAGRAM',
  supportsTitle: false, // Single caption field
  supportsDescription: false,
  supportsCaption: true,
  supportsTags: false,
  supportsHashtags: true,
  supportsCustomThumbnail: true, // cover_url supported
  supportsNativeScheduling: false,
  supportsAnalytics: true,
  requiresCategory: false,
  limits: {
    maxCaptionLength: 2200,
    maxHashtagsCount: 30,
    maxVideoDurationSeconds: 900, // 15 minutes for Reels
    minVideoDurationSeconds: 3,
    maxFileSizeMB: 1000, // 1 GB
    supportedAspectRatios: ['9:16', '1:1', '4:5']
  }
};

export const PLATFORM_CAPABILITIES_MAP: Record<SupportedPlatform, PlatformCapabilities> = {
  YOUTUBE: YOUTUBE_CAPABILITIES,
  TIKTOK: TIKTOK_CAPABILITIES,
  INSTAGRAM: INSTAGRAM_CAPABILITIES,
  // Expansion platforms with fallback baseline
  FACEBOOK: {
    ...INSTAGRAM_CAPABILITIES,
    platform: 'FACEBOOK',
    supportsTitle: true
  },
  LINKEDIN: {
    ...INSTAGRAM_CAPABILITIES,
    platform: 'LINKEDIN',
    supportsTitle: true,
    limits: { ...INSTAGRAM_CAPABILITIES.limits, maxVideoDurationSeconds: 600 }
  },
  TWITTER: {
    ...TIKTOK_CAPABILITIES,
    platform: 'TWITTER',
    limits: { ...TIKTOK_CAPABILITIES.limits, maxCaptionLength: 280, maxVideoDurationSeconds: 140 }
  },
  PINTEREST: {
    ...INSTAGRAM_CAPABILITIES,
    platform: 'PINTEREST',
    supportsTitle: true
  }
};

// 2. Preflight Validation Engine
export interface PlatformValidationIssue {
  platform: SupportedPlatform;
  field: string;
  severity: 'ERROR' | 'WARNING';
  message: string;
}

export function validateContentForPlatform(
  platform: SupportedPlatform,
  metadata: GlobalContentMetadata,
  overrides: PlatformSpecificOverrides,
  media?: MediaAsset
): PlatformValidationIssue[] {
  const issues: PlatformValidationIssue[] = [];
  const caps = PLATFORM_CAPABILITIES_MAP[platform];
  if (!caps) return issues;

  // Title checks
  if (caps.supportsTitle && !metadata.title && !overrides.youtube?.privacyStatus) {
    issues.push({
      platform,
      field: 'title',
      severity: 'ERROR',
      message: `${platform} requires a video title.`
    });
  }

  if (caps.limits.maxTitleLength && metadata.title.length > caps.limits.maxTitleLength) {
    issues.push({
      platform,
      field: 'title',
      severity: 'ERROR',
      message: `Title exceeds maximum allowed length of ${caps.limits.maxTitleLength} characters.`
    });
  }

  // Caption/Description checks
  const effectiveCaption = metadata.caption || metadata.description;
  if (caps.limits.maxCaptionLength && effectiveCaption.length > caps.limits.maxCaptionLength) {
    issues.push({
      platform,
      field: 'caption',
      severity: 'ERROR',
      message: `Caption exceeds maximum allowed length of ${caps.limits.maxCaptionLength} characters.`
    });
  }

  // Hashtags count
  if (caps.limits.maxHashtagsCount && metadata.hashtags.length > caps.limits.maxHashtagsCount) {
    issues.push({
      platform,
      field: 'hashtags',
      severity: 'ERROR',
      message: `Too many hashtags. ${platform} allows a maximum of ${caps.limits.maxHashtagsCount}.`
    });
  }

  // Media duration checks
  if (media && media.durationSeconds) {
    if (media.durationSeconds < caps.limits.minVideoDurationSeconds) {
      issues.push({
        platform,
        field: 'media.duration',
        severity: 'ERROR',
        message: `Video is too short. Minimum duration is ${caps.limits.minVideoDurationSeconds} seconds.`
      });
    }
    if (media.durationSeconds > caps.limits.maxVideoDurationSeconds) {
      issues.push({
        platform,
        field: 'media.duration',
        severity: 'ERROR',
        message: `Video exceeds ${platform} limit of ${Math.round(caps.limits.maxVideoDurationSeconds / 60)} minutes.`
      });
    }
  }

  return issues;
}

// 3. Error Classification System
export interface ClassifiedError {
  category: FailureCategory;
  userFacingMessage: string;
  isRetryable: boolean;
  rawError: unknown;
}

export function classifyPlatformError(platform: SupportedPlatform, error: unknown): ClassifiedError {
  const errStr = error instanceof Error ? error.message : String(error);
  const lower = errStr.toLowerCase();

  // Authentication & Token Expiry
  if (
    lower.includes('token expired') ||
    lower.includes('invalid_token') ||
    lower.includes('oauthexception') ||
    lower.includes('unauthorized') ||
    lower.includes('401')
  ) {
    return {
      category: 'AUTH_REQUIRED',
      userFacingMessage: `Your ${platform} authorization has expired or was revoked. Reconnect the account to resume.`,
      isRetryable: false,
      rawError: error
    };
  }

  // Rate Limiting & Quotas
  if (
    lower.includes('rate limit') ||
    lower.includes('quotaexceeded') ||
    lower.includes('too many requests') ||
    lower.includes('429')
  ) {
    return {
      category: 'RATE_LIMITED',
      userFacingMessage: `${platform} publishing limit reached. OmniPublish will automatically retry when the limit resets.`,
      isRetryable: true,
      rawError: error
    };
  }

  // Media format / codec
  if (
    lower.includes('invalid media') ||
    lower.includes('unsupported codec') ||
    lower.includes('corrupted') ||
    lower.includes('bad frame rate')
  ) {
    return {
      category: 'INVALID_MEDIA',
      userFacingMessage: `The video format does not meet ${platform} encoding requirements.`,
      isRetryable: false,
      rawError: error
    };
  }

  // Transient network / timeouts
  if (
    lower.includes('econnreset') ||
    lower.includes('etimedout') ||
    lower.includes('socket hang up') ||
    lower.includes('503') ||
    lower.includes('504')
  ) {
    return {
      category: 'NETWORK_ERROR',
      userFacingMessage: `Temporary network interruption reaching ${platform}. Automatically retrying.`,
      isRetryable: true,
      rawError: error
    };
  }

  // Default provider error
  return {
    category: 'PROVIDER_ERROR',
    userFacingMessage: `${platform} encountered an unexpected error: ${errStr.slice(0, 140)}`,
    isRetryable: false,
    rawError: error
  };
}

// 4. Platform Adapter Contract
export interface PublishContext {
  batchId: string;
  jobId: string;
  account: SocialAccount;
  media: MediaAsset;
  globalMetadata: GlobalContentMetadata;
  overrides: PlatformSpecificOverrides;
  decryptedAccessToken: string;
}

export interface PublishResult {
  remoteId: string;
  remoteUrl?: string;
  requiresPolling: boolean;
  statusDetails?: string;
}

export interface RemoteStatusResult {
  status: 'PENDING' | 'PROCESSING' | 'SUCCESS' | 'FAILED';
  remoteUrl?: string;
  errorMessage?: string;
}

export abstract class BasePlatformAdapter {
  abstract readonly platform: SupportedPlatform;

  abstract getCapabilities(): PlatformCapabilities;

  abstract publish(context: PublishContext): Promise<PublishResult>;

  abstract getStatus?(remoteId: string, context: PublishContext): Promise<RemoteStatusResult>;

  abstract cancel?(remoteId: string, context: PublishContext): Promise<void>;
}
