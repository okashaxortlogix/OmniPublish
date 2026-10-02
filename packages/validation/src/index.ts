import { z } from 'zod';

// 1. Authentication Schemas
export const RegisterSchema = z.object({
  email: z.string().email('Please provide a valid email address').toLowerCase().trim(),
  password: z
    .string()
    .min(8, 'Password must be at least 8 characters long')
    .max(128, 'Password must not exceed 128 characters')
    .regex(/[A-Z]/, 'Password must contain at least one uppercase letter')
    .regex(/[a-z]/, 'Password must contain at least one lowercase letter')
    .regex(/[0-9]/, 'Password must contain at least one number'),
  fullName: z.string().min(2, 'Full name must be at least 2 characters').max(100).trim(),
  workspaceName: z.string().min(2, 'Workspace name must be at least 2 characters').max(50).trim().optional()
});

export const LoginSchema = z.object({
  email: z.string().email('Invalid email address').toLowerCase().trim(),
  password: z.string().min(1, 'Password is required')
});

// 2. Workspace Schemas
export const CreateWorkspaceSchema = z.object({
  name: z.string().min(2, 'Workspace name must be at least 2 characters').max(50).trim()
});

export const InviteMemberSchema = z.object({
  email: z.string().email().toLowerCase().trim(),
  role: z.enum(['ADMIN', 'EDITOR', 'VIEWER'])
});

// 3. Media Ingestion Schemas
export const InitiateMediaUploadSchema = z.object({
  filename: z.string().min(1).max(255),
  mimeType: z.enum([
    'video/mp4',
    'video/quicktime',
    'video/webm',
    'video/x-matroska'
  ], {
    errorMap: () => ({ message: 'Unsupported video format. Allowed: MP4, MOV, WebM, MKV' })
  }),
  sizeBytes: z.number().positive().max(1024 * 1024 * 1024 * 4, 'Max file size is 4GB')
});

// 4. Composer & Platform Override Schemas
export const GlobalContentMetadataSchema = z.object({
  title: z.string().min(1, 'Title is required').max(100, 'Global title max 100 characters').trim(),
  description: z.string().max(5000, 'Global description max 5,000 characters').default(''),
  caption: z.string().max(2200, 'Caption max 2,200 characters').optional(),
  hashtags: z.array(z.string().regex(/^[a-zA-Z0-9_]+$/, 'Invalid hashtag characters')).max(30).default([]),
  tags: z.array(z.string().max(50)).max(50).default([]),
  thumbnailUrl: z.string().url().optional(),
  visibility: z.enum(['PUBLIC', 'UNLISTED', 'PRIVATE']).default('PUBLIC')
});

export const YouTubeOverrideSchema = z.object({
  categoryId: z.string().default('22'),
  madeForKids: z.boolean().default(false),
  privacyStatus: z.enum(['public', 'unlisted', 'private']).default('public'),
  tags: z.array(z.string()).max(50).optional(),
  publishAt: z.string().datetime().optional()
});

export const TikTokOverrideSchema = z.object({
  privacyLevel: z.enum([
    'PUBLIC_TO_EVERYONE',
    'MUTUAL_FOLLOW_FRIENDS',
    'FOLLOWER_OF_CREATOR',
    'SELF_ONLY'
  ]).default('PUBLIC_TO_EVERYONE'),
  disableComment: z.boolean().default(false),
  disableDuet: z.boolean().default(false),
  disableStitch: z.boolean().default(false),
  brandContentToggle: z.boolean().default(false),
  brandOrganicToggle: z.boolean().default(false)
});

export const InstagramOverrideSchema = z.object({
  shareToFeed: z.boolean().default(true),
  coverUrl: z.string().url().optional(),
  thumbOffsetMs: z.number().nonnegative().optional()
});

export const PlatformOverridesSchema = z.object({
  youtube: YouTubeOverrideSchema.optional(),
  tiktok: TikTokOverrideSchema.optional(),
  instagram: InstagramOverrideSchema.optional()
});

export const CreatePublishBatchSchema = z.object({
  mediaAssetId: z.string().uuid(),
  globalMetadata: GlobalContentMetadataSchema,
  platformOverrides: PlatformOverridesSchema.default({}),
  targetPlatforms: z.array(z.enum(['YOUTUBE', 'TIKTOK', 'INSTAGRAM'])).min(1, 'Select at least one destination platform'),
  scheduledFor: z.string().datetime().optional()
});

export const RetryPublishJobSchema = z.object({
  jobId: z.string().uuid()
});

export type RegisterInput = z.infer<typeof RegisterSchema>;
export type LoginInput = z.infer<typeof LoginSchema>;
export type CreatePublishBatchInput = z.infer<typeof CreatePublishBatchSchema>;
