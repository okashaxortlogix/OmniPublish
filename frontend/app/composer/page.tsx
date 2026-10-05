'use client';

import React, { useState, useEffect } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
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
  X,
  Clock,
  ArrowRight,
  ArrowLeft,
  Check,
  SlidersHorizontal,
  ChevronRight
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
  // 1: Details & Platforms, 2: Review & Schedule
  const [currentStep, setCurrentStep] = useState<number>(1);

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

  // Schedule Options
  const [publishMode, setPublishMode] = useState<'instant' | 'scheduled'>('instant');
  const [scheduledDate, setScheduledDate] = useState('2026-10-06');
  const [scheduledTime, setScheduledTime] = useState('14:00');

  // Platforms State
  const [platforms, setPlatforms] = useState<PlatformSetting[]>([
    { id: 'YOUTUBE', name: 'YouTube', badgeBg: 'bg-red-600', badgeText: 'YT', account: '@alexvance_tech', connected: true, enabled: true },
    { id: 'TIKTOK', name: 'TikTok', badgeBg: 'bg-black', badgeText: 'TT', account: '@alexv_codes', connected: true, enabled: true },
    { id: 'LINKEDIN', name: 'LinkedIn', badgeBg: 'bg-[#0A66C2]', badgeText: 'LI', account: '@omnipublish_linkedin', connected: true, enabled: true },
    { id: 'INSTAGRAM', name: 'Instagram', badgeBg: 'bg-gradient-to-tr from-amber-500 via-pink-600 to-purple-600', badgeText: 'IG', account: '@alexvance.studio', connected: true, enabled: false },
    { id: 'FACEBOOK', name: 'Facebook', badgeBg: 'bg-[#1877F2]', badgeText: 'FB', account: '@omnipublishpage', connected: true, enabled: false },
    { id: 'X', name: 'X (Twitter)', badgeBg: 'bg-black', badgeText: 'X', account: '@omnipublish_app', connected: true, enabled: false },
  ]);

  // Platform Customization Overrides
  const [activeCustomizePlatform, setActiveCustomizePlatform] = useState<SupportedPlatform | null>(null);

  // YouTube Overrides
  const [ytTitle, setYtTitle] = useState('');
  const [ytDescription, setYtDescription] = useState('');
  const [ytCategory, setYtCategory] = useState('28'); // Science & Tech
  const [ytVisibility, setYtVisibility] = useState<'public' | 'unlisted' | 'private'>('public');
  const [ytMadeForKids, setYtMadeForKids] = useState(false);

  // TikTok Overrides
  const [ttCaption, setTtCaption] = useState('');
  const [ttPrivacy, setTtPrivacy] = useState<'PUBLIC_TO_EVERYONE' | 'MUTUAL_FOLLOW_FRIENDS' | 'SELF_ONLY'>('PUBLIC_TO_EVERYONE');
  const [ttAllowComments, setTtAllowComments] = useState(true);
  const [ttAllowDuet, setTtAllowDuet] = useState(true);
  const [ttAllowStitch, setTtAllowStitch] = useState(true);

  // Instagram Overrides
  const [igCaption, setIgCaption] = useState('');
  const [igShareToFeed, setIgShareToFeed] = useState(true);

  // Submission / Review State
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [aiGenerating, setAiGenerating] = useState(false);

  // Fetch real accounts on mount
  useEffect(() => {
    async function loadRealAccounts() {
      try {
        const res = await api.getSocialAccounts();
        if (res && res.accounts && res.accounts.length > 0) {
          const updated = platforms.map((p) => {
            const matched = res.accounts.find(
              (a: any) =>
                a.platform.toUpperCase() === p.id.toUpperCase() ||
                (p.id === 'X' && a.platform.toUpperCase() === 'TWITTER')
            );
            if (matched) {
              return {
                ...p,
                account: matched.account_handle || matched.account_name,
                connected: matched.status === 'ACTIVE' || matched.status === 'EXPIRED',
              };
            }
            return p;
          });
          setPlatforms(updated);
        }
      } catch (e) {
        console.warn('Could not load dynamic accounts for composer', e);
      }
    }
    loadRealAccounts();
  }, []);

  const togglePlatform = (id: SupportedPlatform) => {
    setPlatforms((prev) =>
      prev.map((p) => (p.id === id ? { ...p, enabled: !p.enabled } : p))
    );
  };

  const handleAddHashtag = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && hashtagInput.trim()) {
      e.preventDefault();
      const clean = hashtagInput.startsWith('#') ? hashtagInput.trim() : `#${hashtagInput.trim()}`;
      if (!hashtags.includes(clean)) setHashtags([...hashtags, clean]);
      setHashtagInput('');
    }
  };

  const removeHashtag = (tagToRemove: string) => {
    setHashtags(hashtags.filter((h) => h !== tagToRemove));
  };

  const generateWithAi = () => {
    setAiGenerating(true);
    setTimeout(() => {
      setTitle('How We Scaled Our Social Video Infrastructure to 10M Views');
      setDescription(
        'Here is the exact architecture breakdown of our multi-platform video pipeline: asynchronous queue workers, chunked resumable streaming, and automated token vaults.'
      );
      setHashtags(['#omnipublish', '#softwareengineering', '#buildinpublic', '#scale', '#viral']);
      setAiGenerating(false);
    }, 600);
  };

  const selectedEnabledPlatforms = platforms.filter((p) => p.enabled);

  const handlePublishNow = async () => {
    if (selectedEnabledPlatforms.length === 0) {
      alert('Please select at least one destination channel.');
      return;
    }
    setIsSubmitting(true);
    try {
      const scheduledAtIso =
        publishMode === 'scheduled' && scheduledDate && scheduledTime
          ? new Date(`${scheduledDate}T${scheduledTime}:00`).toISOString()
          : undefined;

      await api.createPublishBatch({
        scheduled_at: scheduledAtIso,
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

      router.push('/publishing');
    } catch (e: any) {
      console.error(e);
      alert('Publish dispatch notice: ' + (e?.message || 'Check connections'));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <AppShell pageTitle="New Publication">
      <div className="max-w-6xl mx-auto space-y-6 pb-16">
        {/* Step Indicator Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200/80">
          <div>
            <h1 className="text-xl font-bold tracking-tight text-slate-900">
              {currentStep === 1 ? 'Compose Publication' : 'Review & Schedule'}
            </h1>
            <p className="text-xs text-slate-500 mt-0.5">
              {currentStep === 1
                ? 'Craft unified video content and select your broadcasting channels.'
                : 'Confirm destination channel compliance and delivery schedule.'}
            </p>
          </div>

          <div className="flex items-center gap-1.5 p-1 rounded-xl bg-slate-100 border border-slate-200/70 text-xs">
            <button
              onClick={() => setCurrentStep(1)}
              className={`px-3 py-1.5 rounded-lg font-semibold transition-all ${
                currentStep === 1
                  ? 'bg-white text-slate-900 shadow-2xs'
                  : 'text-slate-500 hover:text-slate-800'
              }`}
            >
              1. Content &amp; Channels
            </button>
            <ChevronRight className="h-3 w-3 text-slate-400" />
            <button
              onClick={() => setCurrentStep(2)}
              className={`px-3 py-1.5 rounded-lg font-semibold transition-all ${
                currentStep === 2
                  ? 'bg-white text-slate-900 shadow-2xs'
                  : 'text-slate-500 hover:text-slate-800'
              }`}
            >
              2. Review &amp; Schedule
            </button>
          </div>
        </div>

        {/* STEP 1: CONTENT & CHANNELS */}
        {currentStep === 1 && (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            {/* Left Column: Media + Content Inputs (7 cols) */}
            <div className="lg:col-span-7 space-y-5">
              {/* Media Card */}
              <div className="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-2xs">
                <div className="flex flex-col sm:flex-row items-center gap-4">
                  <div className="relative aspect-video w-full sm:w-48 rounded-xl overflow-hidden bg-slate-900 shrink-0 group shadow-2xs">
                    <img
                      src="https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600"
                      alt="Composer video preview"
                      className="w-full h-full object-cover opacity-90"
                    />
                    <div className="absolute inset-0 bg-black/20 flex items-center justify-center">
                      <div className="h-8 w-8 rounded-full bg-white/90 text-slate-900 flex items-center justify-center shadow-xs">
                        <Play className="h-4 w-4 fill-slate-900 ml-0.5" />
                      </div>
                    </div>
                    <span className="absolute bottom-1.5 right-1.5 text-[10px] font-mono bg-black/60 text-white px-1.5 py-0.5 rounded backdrop-blur-xs">
                      01:45
                    </span>
                  </div>

                  <div className="space-y-1.5 flex-1 min-w-0">
                    <div className="flex items-center justify-between">
                      <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Attached Media</span>
                      <span className="text-[10px] font-semibold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                        Ready (1080p MP4)
                      </span>
                    </div>
                    <p className="text-xs font-semibold text-slate-800 truncate">ai_developer_agent_demo.mp4</p>
                    <p className="text-[11px] text-slate-400">45.2 MB • H.264 / AAC • 9:16 Aspect Ratio</p>
                    <div className="pt-1 flex items-center gap-3">
                      <Link
                        href="/upload"
                        className="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-600 hover:text-slate-900 transition-colors"
                      >
                        <RotateCcw className="h-3 w-3" />
                        <span>Replace</span>
                      </Link>
                    </div>
                  </div>
                </div>
              </div>

              {/* Main Metadata Card */}
              <div className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs space-y-4">
                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                  <div>
                    <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Content Metadata</h3>
                    <p className="text-xs text-slate-500">Universal text deployed across all selected channels</p>
                  </div>
                  <button
                    type="button"
                    onClick={generateWithAi}
                    disabled={aiGenerating}
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition-colors p-1"
                    title="Generate viral title and tags"
                  >
                    <Sparkles className={`h-3.5 w-3.5 ${aiGenerating ? 'animate-spin' : ''}`} />
                    <span>{aiGenerating ? 'Optimizing...' : 'AI Assist'}</span>
                  </button>
                </div>

                {/* Title */}
                <div className="space-y-1.5">
                  <div className="flex justify-between text-xs font-semibold text-slate-700">
                    <label>Title</label>
                    <span className="text-[11px] text-slate-400 font-normal">{title.length} / 100</span>
                  </div>
                  <input
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    maxLength={100}
                    placeholder="Write an engaging video title..."
                    className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                  />
                </div>

                {/* Description */}
                <div className="space-y-1.5">
                  <div className="flex justify-between text-xs font-semibold text-slate-700">
                    <label>Description &amp; Caption</label>
                    <span className="text-[11px] text-slate-400 font-normal">{description.length} / 2200</span>
                  </div>
                  <textarea
                    rows={4}
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    maxLength={2200}
                    placeholder="Enter full video description or caption..."
                    className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs leading-relaxed"
                  />
                </div>

                {/* Hashtags Input & Pills */}
                <div className="space-y-1.5">
                  <div className="flex justify-between text-xs font-semibold text-slate-700">
                    <label>Hashtags</label>
                    <span className="text-[11px] text-slate-400 font-normal">{hashtags.length} added</span>
                  </div>
                  <div className="flex flex-wrap items-center gap-1.5 rounded-xl border border-slate-200 bg-white p-2 shadow-2xs min-h-[42px]">
                    {hashtags.map((h) => (
                      <span
                        key={h}
                        className="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-700 font-medium"
                      >
                        {h}
                        <button
                          type="button"
                          onClick={() => removeHashtag(h)}
                          className="text-slate-400 hover:text-slate-700 ml-0.5"
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
                      placeholder="#add_tag (Enter)"
                      className="flex-1 min-w-[120px] bg-transparent text-xs text-slate-800 placeholder-slate-400 focus:outline-none px-1 py-0.5"
                    />
                  </div>
                </div>

                {/* Call to Action */}
                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-slate-700">Call to Action Link / Caption Note</label>
                  <input
                    type="text"
                    value={callToAction}
                    onChange={(e) => setCallToAction(e.target.value)}
                    className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                  />
                </div>
              </div>
            </div>

            {/* Right Column: Channels & Scheduling (5 cols) */}
            <div className="lg:col-span-5 space-y-5">
              {/* Channel Selector Card */}
              <div className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs space-y-4">
                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                  <div>
                    <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Destination Channels</h3>
                    <p className="text-xs text-slate-500">Select where this video will publish</p>
                  </div>
                  <span className="text-xs font-bold text-indigo-600 bg-indigo-50 px-2.5 py-0.5 rounded-full border border-indigo-200">
                    {selectedEnabledPlatforms.length} of {platforms.length}
                  </span>
                </div>

                {/* Platforms List */}
                <div className="space-y-2">
                  {platforms.map((p) => (
                    <div
                      key={p.id}
                      onClick={() => togglePlatform(p.id)}
                      className={`flex items-center justify-between rounded-xl border p-3 cursor-pointer transition-all ${
                        p.enabled
                          ? 'border-slate-900 bg-slate-50/70 shadow-2xs'
                          : 'border-slate-200/70 bg-white hover:bg-slate-50/50'
                      }`}
                    >
                      <div className="flex items-center gap-3 min-w-0">
                        <div
                          className={`flex h-8 w-8 items-center justify-center rounded-xl text-white text-xs font-bold shrink-0 ${p.badgeBg}`}
                        >
                          {p.badgeText}
                        </div>
                        <div className="truncate">
                          <p className="text-xs font-bold text-slate-900 truncate">{p.name}</p>
                          <p className="text-[11px] text-slate-500 truncate">{p.account}</p>
                        </div>
                      </div>

                      <div className="flex items-center gap-2.5 shrink-0" onClick={(e) => e.stopPropagation()}>
                        {p.enabled && (
                          <button
                            type="button"
                            onClick={() => setActiveCustomizePlatform(p.id)}
                            className="text-[11px] font-semibold text-slate-600 hover:text-slate-900 px-2 py-1 rounded-lg hover:bg-slate-200/60 transition-colors"
                          >
                            Customize
                          </button>
                        )}
                        <input
                          type="checkbox"
                          checked={p.enabled}
                          onChange={() => togglePlatform(p.id)}
                          className="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900 cursor-pointer"
                        />
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Delivery Timing Card */}
              <div className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs space-y-3">
                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Release Timing</h3>

                <div className="grid grid-cols-2 gap-2">
                  <button
                    type="button"
                    onClick={() => setPublishMode('instant')}
                    className={`py-2 px-3 rounded-xl text-xs font-semibold border transition-all ${
                      publishMode === 'instant'
                        ? 'border-slate-900 bg-slate-900 text-white shadow-2xs'
                        : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    Publish Now
                  </button>
                  <button
                    type="button"
                    onClick={() => setPublishMode('scheduled')}
                    className={`py-2 px-3 rounded-xl text-xs font-semibold border transition-all ${
                      publishMode === 'scheduled'
                        ? 'border-slate-900 bg-slate-900 text-white shadow-2xs'
                        : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    Schedule Date
                  </button>
                </div>

                {publishMode === 'scheduled' && (
                  <div className="pt-2 grid grid-cols-2 gap-2 animate-fadeIn">
                    <input
                      type="date"
                      value={scheduledDate}
                      onChange={(e) => setScheduledDate(e.target.value)}
                      className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-800 bg-white focus:outline-none focus:border-slate-400"
                    />
                    <input
                      type="time"
                      value={scheduledTime}
                      onChange={(e) => setScheduledTime(e.target.value)}
                      className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-800 bg-white focus:outline-none focus:border-slate-400"
                    />
                  </div>
                )}
              </div>

              {/* Bottom Primary Next Button */}
              <div>
                <button
                  type="button"
                  onClick={() => setCurrentStep(2)}
                  disabled={selectedEnabledPlatforms.length === 0}
                  className="w-full flex items-center justify-center gap-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white py-3 text-xs font-semibold shadow-2xs hover:shadow transition-all disabled:opacity-50 active:scale-98"
                >
                  <span>Continue to Review ({selectedEnabledPlatforms.length} channels)</span>
                  <ArrowRight className="h-4 w-4 text-indigo-400" />
                </button>
              </div>
            </div>
          </div>
        )}

        {/* STEP 2: REVIEW & SCHEDULE VIEW */}
        {currentStep === 2 && (
          <div className="max-w-4xl mx-auto space-y-6">
            <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-2xs space-y-6">
              <div className="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                  <h2 className="text-base font-bold text-slate-900">Publication Summary</h2>
                  <p className="text-xs text-slate-500">Confirm payload details before dispatches are queued</p>
                </div>
                <button
                  onClick={() => setCurrentStep(1)}
                  className="text-xs font-semibold text-indigo-600 hover:underline"
                >
                  Edit Content
                </button>
              </div>

              {/* Post Preview Info */}
              <div className="space-y-3 text-xs">
                <div>
                  <span className="text-slate-400 font-medium">Title:</span>
                  <p className="font-bold text-slate-900 text-sm mt-0.5">{title}</p>
                </div>
                <div>
                  <span className="text-slate-400 font-medium">Description:</span>
                  <p className="text-slate-700 leading-relaxed mt-0.5">{description}</p>
                </div>
                <div>
                  <span className="text-slate-400 font-medium">Hashtags:</span>
                  <div className="flex flex-wrap gap-1.5 mt-1">
                    {hashtags.map((h) => (
                      <span key={h} className="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-mono text-slate-700">
                        {h}
                      </span>
                    ))}
                  </div>
                </div>
              </div>

              {/* Selected Destination Readiness */}
              <div className="space-y-3 pt-3 border-t border-slate-100">
                <h4 className="text-xs font-bold uppercase tracking-wider text-slate-400">Target Channels Readiness</h4>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  {selectedEnabledPlatforms.map((p) => (
                    <div key={p.id} className="p-3 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-between">
                      <div className="flex items-center gap-2.5">
                        <div className={`h-7 w-7 rounded-lg text-white font-bold text-xs flex items-center justify-center ${p.badgeBg}`}>
                          {p.badgeText}
                        </div>
                        <div>
                          <p className="text-xs font-bold text-slate-900">{p.name}</p>
                          <p className="text-[10px] text-slate-500">{p.account}</p>
                        </div>
                      </div>
                      <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600">
                        <CheckCircle2 className="h-3.5 w-3.5" />
                        Ready
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Actions Footer */}
              <div className="pt-4 border-t border-slate-100 flex items-center justify-between">
                <button
                  type="button"
                  onClick={() => setCurrentStep(1)}
                  className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-slate-900"
                >
                  <ArrowLeft className="h-4 w-4" />
                  <span>Back to Details</span>
                </button>

                <button
                  type="button"
                  onClick={handlePublishNow}
                  disabled={isSubmitting || selectedEnabledPlatforms.length === 0}
                  className="inline-flex items-center gap-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white px-6 py-2.5 text-xs font-semibold shadow-2xs hover:shadow active:scale-95 transition-all disabled:opacity-50"
                >
                  {isSubmitting ? (
                    <span>Dispatching Workers...</span>
                  ) : (
                    <>
                      <Send className="h-3.5 w-3.5 text-indigo-400" />
                      <span>{publishMode === 'scheduled' ? 'Confirm Schedule' : 'Publish Now'}</span>
                    </>
                  )}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* MODAL: PLATFORM OVERRIDES */}
        {activeCustomizePlatform && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-xs p-4 animate-fadeIn">
            <div className="w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-xl overflow-hidden p-6 space-y-5">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                <div className="flex items-center gap-2">
                  <SlidersHorizontal className="h-4 w-4 text-indigo-600" />
                  <h3 className="text-sm font-bold text-slate-900">
                    Customize for {platforms.find((p) => p.id === activeCustomizePlatform)?.name}
                  </h3>
                </div>
                <button
                  type="button"
                  onClick={() => setActiveCustomizePlatform(null)}
                  className="p-1 rounded-lg text-slate-400 hover:bg-slate-100"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>

              {activeCustomizePlatform === 'YOUTUBE' && (
                <div className="space-y-4 text-xs">
                  <div className="space-y-1">
                    <label className="font-semibold text-slate-700">Custom YouTube Title</label>
                    <input
                      type="text"
                      value={ytTitle}
                      onChange={(e) => setYtTitle(e.target.value)}
                      placeholder={title}
                      className="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="font-semibold text-slate-700">YouTube Visibility</label>
                    <select
                      value={ytVisibility}
                      onChange={(e: any) => setYtVisibility(e.target.value)}
                      className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                    >
                      <option value="public">Public</option>
                      <option value="unlisted">Unlisted</option>
                      <option value="private">Private</option>
                    </select>
                  </div>
                </div>
              )}

              {activeCustomizePlatform === 'TIKTOK' && (
                <div className="space-y-4 text-xs">
                  <div className="space-y-1">
                    <label className="font-semibold text-slate-700">Custom TikTok Caption</label>
                    <textarea
                      rows={3}
                      value={ttCaption}
                      onChange={(e) => setTtCaption(e.target.value)}
                      placeholder={description}
                      className="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="font-semibold text-slate-700">Privacy Setting</label>
                    <select
                      value={ttPrivacy}
                      onChange={(e: any) => setTtPrivacy(e.target.value)}
                      className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                    >
                      <option value="PUBLIC_TO_EVERYONE">Public to Everyone</option>
                      <option value="MUTUAL_FOLLOW_FRIENDS">Mutual Friends</option>
                      <option value="SELF_ONLY">Only Me</option>
                    </select>
                  </div>
                </div>
              )}

              {activeCustomizePlatform !== 'YOUTUBE' && activeCustomizePlatform !== 'TIKTOK' && (
                <div className="py-4 text-center text-xs text-slate-500">
                  Universal content metadata will be applied automatically according to {activeCustomizePlatform} guidelines.
                </div>
              )}

              <div className="pt-3 border-t border-slate-100 flex justify-end">
                <button
                  type="button"
                  onClick={() => setActiveCustomizePlatform(null)}
                  className="rounded-xl bg-slate-900 text-white px-4 py-2 text-xs font-semibold hover:bg-slate-800 transition-colors"
                >
                  Save Overrides
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
