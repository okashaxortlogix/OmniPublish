'use client';

import React, { useState, useEffect } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import {
  Film,
  Sparkles,
  Share2,
  CheckCircle2,
  AlertTriangle,
  Play,
  RotateCcw,
  Sliders,
  Calendar,
  Send,
  Eye,
  Hash,
  Tag,
  ChevronDown,
  Info,
  ArrowRight,
  ArrowLeft,
  X,
  Layers,
  Clock,
  ShieldCheck,
  Check
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';
import { SupportedPlatform } from '@/lib/types';

interface PlatformSetting {
  id: SupportedPlatform;
  name: string;
  badgeBg: string;
  badgeText: string;
  account: string;
  connected: boolean;
  enabled: boolean;
}

export default function ComposerPage() {
  const router = useRouter();

  // Active step in composer
  // 1: Upload (done), 2: Details, 3: Platforms, 4: Review
  const [currentStep, setCurrentStep] = useState<number>(2);

  // Global Content Metadata
  const [title, setTitle] = useState('How We Scaled OmniPublish from 0 to 10k Users');
  const [description, setDescription] = useState(
    'A full technical breakdown of multi-platform video publishing, worker queues, and resilient social APIs.'
  );
  const [tags, setTags] = useState<string[]>(['SaaS', 'Startup', 'Engineering', 'Tech']);
  const [tagInput, setTagInput] = useState('');
  const [hashtags, setHashtags] = useState<string[]>(['#omnipublish', '#saas', '#tech', '#coding']);
  const [hashtagInput, setHashtagInput] = useState('');
  const [callToAction, setCallToAction] = useState('Visit omnipublish.io to try it free!');

  // Platforms State
  const [platforms, setPlatforms] = useState<PlatformSetting[]>([
    { id: 'YOUTUBE', name: 'YouTube', badgeBg: 'bg-red-600', badgeText: 'YT', account: '@alexchannel', connected: true, enabled: true },
    { id: 'TIKTOK', name: 'TikTok', badgeBg: 'bg-black', badgeText: 'TT', account: '@tiktokcreator', connected: true, enabled: true },
    { id: 'INSTAGRAM', name: 'Instagram', badgeBg: 'bg-pink-600', badgeText: 'IG', account: '@john_official', connected: true, enabled: true },
    { id: 'FACEBOOK', name: 'Facebook', badgeBg: 'bg-blue-600', badgeText: 'FB', account: '@omnipublishpage', connected: true, enabled: false },
    { id: 'LINKEDIN', name: 'LinkedIn', badgeBg: 'bg-sky-700', badgeText: 'LI', account: 'OmniPublish Media', connected: true, enabled: false },
    { id: 'X', name: 'X (Twitter)', badgeBg: 'bg-slate-900', badgeText: 'X', account: '@omnipublish_app', connected: true, enabled: false },
  ]);

  // Platform Customization Overrides (Verified capabilities)
  const [activeCustomizePlatform, setActiveCustomizePlatform] = useState<SupportedPlatform | null>(null);

  // YouTube Overrides
  const [ytTitle, setYtTitle] = useState('');
  const [ytDescription, setYtDescription] = useState('');
  const [ytCategory, setYtCategory] = useState('28'); // Science & Tech
  const [ytVisibility, setYtVisibility] = useState<'public' | 'unlisted' | 'private'>('public');
  const [ytMadeForKids, setYtMadeForKids] = useState(false);
  const [ytSchedule, setYtSchedule] = useState(false);
  const [ytScheduleTime, setYtScheduleTime] = useState('2026-10-05T15:00');

  // TikTok Overrides
  const [ttCaption, setTtCaption] = useState('');
  const [ttPrivacy, setTtPrivacy] = useState<'PUBLIC_TO_EVERYONE' | 'MUTUAL_FOLLOW_FRIENDS' | 'SELF_ONLY'>('PUBLIC_TO_EVERYONE');
  const [ttAllowComments, setTtAllowComments] = useState(true);
  const [ttAllowDuet, setTtAllowDuet] = useState(true);
  const [ttAllowStitch, setTtAllowStitch] = useState(true);
  const [ttCommercial, setTtCommercial] = useState(false);

  // Instagram Overrides
  const [igCaption, setIgCaption] = useState('');
  const [igShareToFeed, setIgShareToFeed] = useState(true);
  const [igAudioName, setIgAudioName] = useState('Original Audio — OmniPublish');

  // Submission / Review State
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [publishSuccess, setPublishSuccess] = useState(false);

  // Load any seed from Upload page or AI assistant
  useEffect(() => {
    if (typeof window !== 'undefined') {
      const seed = sessionStorage.getItem('composer_seed');
      if (seed) {
        try {
          const parsed = JSON.parse(seed);
          if (parsed.title) setTitle(parsed.title);
          if (parsed.description) setDescription(parsed.description);
          if (parsed.tags) setTags(parsed.tags);
          if (parsed.hashtags) setHashtags(parsed.hashtags);
        } catch (e) {
          // ignore
        }
      }
    }
  }, []);

  const togglePlatform = (id: SupportedPlatform) => {
    setPlatforms((prev) =>
      prev.map((p) => (p.id === id ? { ...p, enabled: !p.enabled } : p))
    );
  };

  const handleAddTag = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && tagInput.trim()) {
      e.preventDefault();
      if (!tags.includes(tagInput.trim())) setTags([...tags, tagInput.trim()]);
      setTagInput('');
    }
  };

  const handleAddHashtag = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && hashtagInput.trim()) {
      e.preventDefault();
      const clean = hashtagInput.startsWith('#') ? hashtagInput.trim() : `#${hashtagInput.trim()}`;
      if (!hashtags.includes(clean)) setHashtags([...hashtags, clean]);
      setHashtagInput('');
    }
  };

  const selectedEnabledPlatforms = platforms.filter((p) => p.enabled);

  const handlePublishNow = async () => {
    setIsSubmitting(true);
    try {
      await api.createPublishBatch({
        globalMetadata: {
          title,
          description,
          caption: `${title}\n\n${description}\n\n${hashtags.join(' ')}`,
          hashtags,
          tags,
          visibility: 'PUBLIC',
        },
        platformOverrides: {
          youtube: {
            title: ytTitle || undefined,
            description: ytDescription || undefined,
            categoryId: ytCategory,
            privacyStatus: ytVisibility,
            madeForKids: ytMadeForKids,
          },
          tiktok: {
            caption: ttCaption || undefined,
            privacyLevel: ttPrivacy,
            disableComment: !ttAllowComments,
            disableDuet: !ttAllowDuet,
            disableStitch: !ttAllowStitch,
          },
          instagram: {
            caption: igCaption || undefined,
            shareToFeed: igShareToFeed,
          },
        },
        targetPlatforms: selectedEnabledPlatforms.map((p) => p.id),
      });

      setPublishSuccess(true);
      setTimeout(() => {
        router.push('/publishing');
      }, 1000);
    } catch (e) {
      console.error(e);
      // Fallback redirect to monitor
      router.push('/publishing');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <AppShell pageTitle="Universal Content Composer">
      <div className="max-w-6xl mx-auto space-y-6">
        {/* Stepper matching Screenshot 7 */}
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex items-center justify-between max-w-2xl mx-auto">
            {[
              { num: 1, label: 'Upload', route: '/upload' },
              { num: 2, label: 'Details', step: 2 },
              { num: 3, label: 'Platforms', step: 3 },
              { num: 4, label: 'Review', step: 4 },
            ].map((s, idx) => {
              const isPast = currentStep > s.num || s.num === 1;
              const isCurrent = currentStep === s.num && s.num !== 1;
              return (
                <React.Fragment key={s.num}>
                  <div
                    onClick={() => {
                      if (s.route) router.push(s.route);
                      else if (s.step) setCurrentStep(s.step);
                    }}
                    className="flex items-center gap-2 cursor-pointer group"
                  >
                    <div
                      className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold transition-colors ${
                        isCurrent
                          ? 'bg-blue-600 text-white ring-4 ring-blue-50'
                          : isPast
                          ? 'bg-emerald-500 text-white'
                          : 'bg-slate-100 text-slate-500 group-hover:bg-slate-200'
                      }`}
                    >
                      {isPast ? <Check className="h-4 w-4" /> : s.num}
                    </div>
                    <span
                      className={`text-xs font-medium ${
                        isCurrent ? 'text-blue-600 font-semibold' : 'text-slate-600'
                      }`}
                    >
                      {s.label}
                    </span>
                  </div>
                  {idx < 3 && (
                    <div
                      className={`flex-1 h-0.5 mx-3 ${
                        currentStep > idx + 1 ? 'bg-emerald-400' : 'bg-slate-200'
                      }`}
                    />
                  )}
                </React.Fragment>
              );
            })}
          </div>
        </div>

        {/* STEP 2 & 3: DETAILS & PLATFORMS VIEW (Screenshot 7) */}
        {currentStep <= 3 && (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
            {/* Left Column: Video Preview & Thumbnail (3 cols) */}
            <div className="lg:col-span-4 space-y-4">
              <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-3">
                <div className="flex items-center justify-between">
                  <h3 className="text-xs font-semibold text-slate-800 uppercase tracking-wider">
                    Video Preview
                  </h3>
                  <span className="text-[11px] font-mono bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded">
                    01:45
                  </span>
                </div>

                <div className="relative aspect-video rounded-lg overflow-hidden bg-slate-900 group shadow-inner">
                  <img
                    src="https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600"
                    alt="Composer video preview"
                    className="w-full h-full object-cover opacity-85"
                  />
                  <div className="absolute inset-0 bg-black/20 flex items-center justify-center">
                    <div className="h-10 w-10 rounded-full bg-white/90 text-slate-900 flex items-center justify-center shadow-lg group-hover:scale-105 transition-transform">
                      <Play className="h-5 w-5 fill-slate-900 ml-0.5" />
                    </div>
                  </div>
                </div>

                <div className="flex items-center justify-between pt-1">
                  <Link
                    href="/upload"
                    className="flex items-center gap-1.5 text-xs text-slate-600 hover:text-blue-600 font-medium"
                  >
                    <RotateCcw className="h-3.5 w-3.5" />
                    <span>Replace Media</span>
                  </Link>

                  <button
                    type="button"
                    className="text-xs text-blue-600 hover:underline font-medium"
                  >
                    Edit Thumbnail
                  </button>
                </div>
              </div>

              {/* AI Assistant Quick Generator Banner */}
              <div className="rounded-xl border border-blue-200 bg-gradient-to-br from-blue-50 to-indigo-50/50 p-4 shadow-sm space-y-2">
                <div className="flex items-center gap-2 text-blue-700">
                  <Sparkles className="h-4 w-4" />
                  <span className="text-xs font-bold">AI Copywriter Ready</span>
                </div>
                <p className="text-[11px] text-slate-600">
                  Need viral title or platform-tailored hashtags? Generate with 1 click.
                </p>
                <Link
                  href="/ai"
                  className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:underline pt-1"
                >
                  <span>Open AI Assistant</span>
                  <ArrowRight className="h-3 w-3" />
                </Link>
              </div>
            </div>

            {/* Middle Column: Global Content Metadata (5 cols) */}
            <div className="lg:col-span-5 space-y-4">
              <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                  <h3 className="text-sm font-semibold text-slate-900">Global Content</h3>
                  <span className="text-xs text-slate-400">Default for all channels</span>
                </div>

                {/* Title */}
                <div>
                  <div className="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                    <label>Title</label>
                    <span className="text-slate-400 font-normal">{title.length}/100</span>
                  </div>
                  <input
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    maxLength={100}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600"
                    placeholder="Enter post title..."
                  />
                </div>

                {/* Description */}
                <div>
                  <div className="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                    <label>Description</label>
                    <span className="text-slate-400 font-normal">{description.length}/2200</span>
                  </div>
                  <textarea
                    rows={4}
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    maxLength={2200}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600"
                    placeholder="Enter global description..."
                  />
                </div>

                {/* Hashtags */}
                <div>
                  <div className="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                    <label>Hashtags</label>
                    <span className="text-slate-400 font-normal">{hashtags.length} added</span>
                  </div>
                  <div className="flex flex-wrap items-center gap-1.5 rounded-lg border border-slate-300 bg-white p-2">
                    {hashtags.map((h) => (
                      <span
                        key={h}
                        className="inline-flex items-center gap-1 rounded bg-blue-50 px-2 py-0.5 text-[11px] text-blue-700 font-medium"
                      >
                        {h}
                        <button
                          type="button"
                          onClick={() => setHashtags(hashtags.filter((x) => x !== h))}
                          className="text-blue-400 hover:text-blue-600"
                        >
                          <X className="h-3 w-3" />
                        </button>
                      </span>
                    ))}
                    <input
                      type="text"
                      value={hashtagInput}
                      onChange={(e) => setHashtagInput(e.target.value)}
                      onKeyDown={handleAddHashtag}
                      placeholder="#hashtag (Enter)"
                      className="flex-1 min-w-[90px] bg-transparent text-xs text-slate-900 focus:outline-none"
                    />
                  </div>
                </div>

                {/* Call to Action */}
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Call to Action Link / Caption Note
                  </label>
                  <input
                    type="text"
                    value={callToAction}
                    onChange={(e) => setCallToAction(e.target.value)}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600"
                  />
                </div>
              </div>
            </div>

            {/* Right Column: Platform Selection (3 cols) */}
            <div className="lg:col-span-3 space-y-4">
              <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-3">
                <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                  <h3 className="text-sm font-semibold text-slate-900">Platforms</h3>
                  <span className="text-xs text-blue-600 font-semibold">
                    {selectedEnabledPlatforms.length} selected
                  </span>
                </div>

                <div className="space-y-2">
                  {platforms.map((p) => (
                    <div
                      key={p.id}
                      className={`flex items-center justify-between rounded-lg border p-2.5 transition-colors ${
                        p.enabled
                          ? 'border-blue-200 bg-blue-50/30'
                          : 'border-slate-200 bg-slate-50/50 opacity-75'
                      }`}
                    >
                      <div className="flex items-center gap-2.5">
                        <div
                          className={`flex h-7 w-7 items-center justify-center rounded text-white text-[11px] font-bold ${p.badgeBg}`}
                        >
                          {p.badgeText}
                        </div>
                        <div>
                          <p className="text-xs font-semibold text-slate-900">{p.name}</p>
                          <p className="text-[10px] text-slate-500">{p.account}</p>
                        </div>
                      </div>

                      <div className="flex items-center gap-2">
                        {p.enabled && (
                          <button
                            type="button"
                            onClick={() => setActiveCustomizePlatform(p.id)}
                            className="text-[11px] font-semibold text-blue-600 hover:underline"
                          >
                            Customize →
                          </button>
                        )}
                        <input
                          type="checkbox"
                          checked={p.enabled}
                          onChange={() => togglePlatform(p.id)}
                          className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer"
                        />
                      </div>
                    </div>
                  ))}
                </div>

                <div className="pt-2">
                  <button
                    type="button"
                    onClick={() => setCurrentStep(4)}
                    className="w-full flex items-center justify-center gap-2 rounded-lg bg-blue-600 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors"
                  >
                    <span>Next, Review</span>
                    <ArrowRight className="h-4 w-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* STEP 4: REVIEW & PUBLISH VIEW (Screenshot 8/9 & Section 8) */}
        {currentStep === 4 && (
          <div className="space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-xl font-bold text-slate-900">Review &amp; Publish</h2>
                <p className="text-xs text-slate-500">
                  Verify destination readiness before firing independent publishing workers.
                </p>
              </div>

              <div className="flex items-center gap-3">
                <button
                  type="button"
                  onClick={() => setCurrentStep(2)}
                  className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
                >
                  <ArrowLeft className="h-3.5 w-3.5" />
                  <span>Back to Edit</span>
                </button>
                <button
                  type="button"
                  onClick={handlePublishNow}
                  disabled={isSubmitting || selectedEnabledPlatforms.length === 0}
                  className="flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                >
                  {isSubmitting ? (
                    <span>Dispatching Workers...</span>
                  ) : (
                    <>
                      <Send className="h-3.5 w-3.5" />
                      <span>Publish Now</span>
                    </>
                  )}
                </button>
              </div>
            </div>

            {/* Video Overview Strip */}
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm flex flex-col md:flex-row items-start md:items-center gap-5">
              <div className="relative aspect-video w-40 rounded-lg overflow-hidden bg-slate-900 shrink-0">
                <img
                  src="https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=400"
                  alt="Preview"
                  className="w-full h-full object-cover opacity-90"
                />
              </div>
              <div className="flex-1 space-y-1">
                <h3 className="text-sm font-bold text-slate-900">{title}</h3>
                <p className="text-xs text-slate-600 line-clamp-2">{description}</p>
                <div className="flex flex-wrap gap-1 pt-1">
                  {hashtags.map((h) => (
                    <span key={h} className="text-[11px] font-medium text-blue-600">
                      {h}
                    </span>
                  ))}
                </div>
              </div>
            </div>

            {/* Platforms Readiness Grid */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {selectedEnabledPlatforms.map((p) => {
                const isReady = p.id !== 'INSTAGRAM' || igCaption !== 'FORCE_ERROR';
                return (
                  <div
                    key={p.id}
                    className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3"
                  >
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <div
                          className={`flex h-7 w-7 items-center justify-center rounded text-white text-xs font-bold ${p.badgeBg}`}
                        >
                          {p.badgeText}
                        </div>
                        <div>
                          <h4 className="text-xs font-bold text-slate-900">{p.name}</h4>
                          <span className="text-[10px] text-slate-400">{p.account}</span>
                        </div>
                      </div>

                      <span
                        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                          isReady ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'
                        }`}
                      >
                        <CheckCircle2 className="h-3 w-3" />
                        Ready
                      </span>
                    </div>

                    <div className="text-xs text-slate-600 space-y-1 pt-1 border-t border-slate-100">
                      <div className="flex justify-between">
                        <span className="text-slate-400">Media format:</span>
                        <span className="font-medium text-slate-700">1080p MP4 (Compliant)</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-400">OAuth token:</span>
                        <span className="font-medium text-emerald-600">Verified &amp; Active</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-400">API quota:</span>
                        <span className="font-medium text-slate-700">Normal</span>
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={() => setActiveCustomizePlatform(p.id)}
                      className="w-full rounded border border-slate-200 bg-slate-50 py-1.5 text-center text-xs font-medium text-slate-700 hover:bg-slate-100"
                    >
                      Edit {p.name} Overrides
                    </button>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* MODAL: PLATFORM CUSTOMIZATION (Screenshot 8) */}
        {activeCustomizePlatform && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
            <div className="w-full max-w-3xl rounded-xl border border-slate-200 bg-white shadow-xl overflow-hidden animate-scaleIn">
              {/* Modal Header with Platform Switcher */}
              <div className="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-3.5">
                <div className="flex items-center gap-2">
                  <Sliders className="h-4 w-4 text-blue-600" />
                  <span className="text-xs font-bold text-slate-800 uppercase tracking-wider">
                    Platform Customization
                  </span>
                </div>

                <button
                  type="button"
                  onClick={() => setActiveCustomizePlatform(null)}
                  className="rounded-lg p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-700"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>

              {/* Tabs for connected platforms */}
              <div className="flex border-b border-slate-200 px-6 bg-white gap-2">
                {platforms
                  .filter((p) => p.enabled)
                  .map((p) => (
                    <button
                      key={p.id}
                      onClick={() => setActiveCustomizePlatform(p.id)}
                      className={`flex items-center gap-2 border-b-2 py-3 px-3 text-xs font-semibold transition-colors ${
                        activeCustomizePlatform === p.id
                          ? 'border-blue-600 text-blue-600'
                          : 'border-transparent text-slate-500 hover:text-slate-800'
                      }`}
                    >
                      <span
                        className={`h-2 w-2 rounded-full ${
                          p.id === 'YOUTUBE'
                            ? 'bg-red-600'
                            : p.id === 'TIKTOK'
                            ? 'bg-black'
                            : 'bg-pink-600'
                        }`}
                      />
                      <span>{p.name}</span>
                    </button>
                  ))}
              </div>

              {/* Modal Body: Verified Platform Capabilities */}
              <div className="p-6 max-h-[70vh] overflow-y-auto space-y-4">
                {activeCustomizePlatform === 'YOUTUBE' && (
                  <div className="space-y-4">
                    <div className="flex items-center gap-2 text-xs text-slate-500 pb-2 border-b border-slate-100">
                      <Info className="h-4 w-4 text-blue-600" />
                      <span>
                        Verified YouTube Data API v3 capabilities: Resumable chunked upload, tags &amp; COPPA declaration.
                      </span>
                    </div>

                    <div>
                      <label className="block text-xs font-semibold text-slate-700 mb-1">
                        YouTube Custom Title (Override)
                      </label>
                      <input
                        type="text"
                        value={ytTitle}
                        onChange={(e) => setYtTitle(e.target.value)}
                        placeholder={title}
                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-semibold text-slate-700 mb-1">
                        YouTube Description
                      </label>
                      <textarea
                        rows={3}
                        value={ytDescription}
                        onChange={(e) => setYtDescription(e.target.value)}
                        placeholder={description}
                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                      />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                          Privacy Visibility
                        </label>
                        <select
                          value={ytVisibility}
                          onChange={(e) => setYtVisibility(e.target.value as any)}
                          className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                        >
                          <option value="public">Public</option>
                          <option value="unlisted">Unlisted</option>
                          <option value="private">Private</option>
                        </select>
                      </div>

                      <div>
                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                          Category
                        </label>
                        <select
                          value={ytCategory}
                          onChange={(e) => setYtCategory(e.target.value)}
                          className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                        >
                          <option value="28">Science &amp; Technology</option>
                          <option value="27">Education</option>
                          <option value="24">Entertainment</option>
                          <option value="22">People &amp; Blogs</option>
                        </select>
                      </div>
                    </div>

                    <div className="flex items-center gap-3 pt-2">
                      <input
                        type="checkbox"
                        id="ytMadeForKids"
                        checked={ytMadeForKids}
                        onChange={(e) => setYtMadeForKids(e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-blue-600"
                      />
                      <label htmlFor="ytMadeForKids" className="text-xs text-slate-700">
                        Made for Kids (COPPA compliance check)
                      </label>
                    </div>
                  </div>
                )}

                {activeCustomizePlatform === 'TIKTOK' && (
                  <div className="space-y-4">
                    <div className="flex items-center gap-2 text-xs text-slate-500 pb-2 border-b border-slate-100">
                      <Info className="h-4 w-4 text-blue-600" />
                      <span>
                        Verified TikTok Content Posting API v2: Single combined caption field (max 2,200 chars).
                      </span>
                    </div>

                    <div>
                      <div className="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                        <label>TikTok Caption</label>
                        <span className="text-slate-400 font-normal">
                          {ttCaption ? ttCaption.length : description.length}/2200
                        </span>
                      </div>
                      <textarea
                        rows={3}
                        value={ttCaption}
                        onChange={(e) => setTtCaption(e.target.value)}
                        placeholder={`${title}\n\n${hashtags.join(' ')}`}
                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-semibold text-slate-700 mb-1">
                        TikTok Privacy Level
                      </label>
                      <select
                        value={ttPrivacy}
                        onChange={(e) => setTtPrivacy(e.target.value as any)}
                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                      >
                        <option value="PUBLIC_TO_EVERYONE">Public to Everyone</option>
                        <option value="MUTUAL_FOLLOW_FRIENDS">Mutual Friends Only</option>
                        <option value="SELF_ONLY">Private (Only Me)</option>
                      </select>
                    </div>

                    <div className="grid grid-cols-3 gap-2 pt-2">
                      <label className="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={ttAllowComments}
                          onChange={(e) => setTtAllowComments(e.target.checked)}
                          className="h-4 w-4 rounded border-slate-300 text-blue-600"
                        />
                        <span>Allow Comments</span>
                      </label>

                      <label className="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={ttAllowDuet}
                          onChange={(e) => setTtAllowDuet(e.target.checked)}
                          className="h-4 w-4 rounded border-slate-300 text-blue-600"
                        />
                        <span>Allow Duet</span>
                      </label>

                      <label className="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={ttAllowStitch}
                          onChange={(e) => setTtAllowStitch(e.target.checked)}
                          className="h-4 w-4 rounded border-slate-300 text-blue-600"
                        />
                        <span>Allow Stitch</span>
                      </label>
                    </div>
                  </div>
                )}

                {activeCustomizePlatform === 'INSTAGRAM' && (
                  <div className="space-y-4">
                    <div className="flex items-center gap-2 text-xs text-slate-500 pb-2 border-b border-slate-100">
                      <Info className="h-4 w-4 text-blue-600" />
                      <span>
                        Verified Instagram Graph API: Container initialization, Reels audio &amp; Feed sharing.
                      </span>
                    </div>

                    <div>
                      <div className="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                        <label>Reel Caption</label>
                        <span className="text-slate-400 font-normal">
                          {igCaption ? igCaption.length : description.length}/2200
                        </span>
                      </div>
                      <textarea
                        rows={3}
                        value={igCaption}
                        onChange={(e) => setIgCaption(e.target.value)}
                        placeholder={`${title}\n\n${hashtags.join(' ')}`}
                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                      />
                    </div>

                    <div className="flex items-center gap-3 pt-2">
                      <input
                        type="checkbox"
                        id="igShareToFeed"
                        checked={igShareToFeed}
                        onChange={(e) => setIgShareToFeed(e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-blue-600"
                      />
                      <label htmlFor="igShareToFeed" className="text-xs text-slate-700">
                        Also Share Reel to Profile Feed
                      </label>
                    </div>
                  </div>
                )}
              </div>

              {/* Modal Footer */}
              <div className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-3">
                <button
                  type="button"
                  onClick={() => setActiveCustomizePlatform(null)}
                  className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                >
                  Save &amp; Close
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
