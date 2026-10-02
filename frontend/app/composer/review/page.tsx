'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  Send,
  ArrowLeft,
  CheckCircle2,
  AlertTriangle,
  Play,
  Share2,
  Clock,
  ShieldCheck,
  Calendar
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';

export default function ComposerReviewPage() {
  const router = useRouter();
  const [isPublishing, setIsPublishing] = useState(false);
  const [publishSuccess, setPublishSuccess] = useState(false);

  const handlePublish = async () => {
    setIsPublishing(true);
    try {
      await api.createPublishBatch({
        globalMetadata: {
          title: 'How We Scaled OmniPublish from 0 to 10k Users',
          description: 'A full technical breakdown of multi-platform video publishing.',
          caption: 'How We Scaled OmniPublish from 0 to 10k Users! #omnipublish #saas',
          hashtags: ['#omnipublish', '#saas', '#tech'],
          tags: ['SaaS', 'Engineering'],
          visibility: 'PUBLIC',
        },
        platformOverrides: {
          youtube: { categoryId: '28', privacyStatus: 'public' },
          tiktok: { privacyLevel: 'PUBLIC_TO_EVERYONE' },
          instagram: { shareToFeed: true },
        },
        targetPlatforms: ['YOUTUBE', 'TIKTOK', 'INSTAGRAM'],
      });
      setPublishSuccess(true);
      setTimeout(() => router.push('/publishing'), 1200);
    } catch (e) {
      router.push('/publishing');
    } finally {
      setIsPublishing(false);
    }
  };

  return (
    <AppShell pageTitle="Review & Publish">
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Review &amp; Publish</h1>
            <p className="text-xs text-slate-500 mt-0.5">
              Verify platform readiness, compliance checks, and target destinations.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <Link
              href="/composer"
              className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
            >
              <ArrowLeft className="h-4 w-4" />
              <span>Back to Edit</span>
            </Link>

            <button
              onClick={handlePublish}
              disabled={isPublishing}
              className="flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors disabled:opacity-50"
            >
              {isPublishing ? (
                <span>Dispatching Workers...</span>
              ) : (
                <>
                  <Send className="h-3.5 w-3.5" />
                  <span>Publish Everywhere</span>
                </>
              )}
            </button>
          </div>
        </div>

        {/* Video Preview Summary */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm flex flex-col md:flex-row items-start md:items-center gap-5">
          <div className="relative aspect-video w-48 rounded-lg overflow-hidden bg-slate-900 shrink-0">
            <img
              src="https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=400"
              alt="Video thumbnail"
              className="w-full h-full object-cover opacity-90"
            />
            <div className="absolute inset-0 bg-black/20 flex items-center justify-center">
              <Play className="h-6 w-6 fill-white text-white opacity-80" />
            </div>
            <span className="absolute bottom-2 right-2 rounded bg-black/75 px-1.5 py-0.5 text-[10px] font-mono text-white">
              01:45
            </span>
          </div>

          <div className="flex-1 space-y-1.5">
            <div className="flex items-center gap-2">
              <span className="rounded bg-blue-50 text-blue-700 px-2 py-0.5 text-[11px] font-semibold">
                Master 1080p MP4
              </span>
              <span className="text-xs text-slate-400">•</span>
              <span className="text-xs text-slate-500">48.2 MB</span>
            </div>
            <h3 className="text-base font-bold text-slate-900">
              How We Scaled OmniPublish from 0 to 10k Users
            </h3>
            <p className="text-xs text-slate-600 line-clamp-2">
              A full technical breakdown of multi-platform video publishing, worker queues, and resilient social APIs.
            </p>
          </div>
        </div>

        {/* Selected Destinations Readiness */}
        <div className="space-y-3">
          <h3 className="text-sm font-semibold text-slate-900">Target Destinations</h3>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {/* YouTube Card */}
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-red-600 text-white text-xs font-bold">
                    YT
                  </div>
                  <div>
                    <h4 className="text-xs font-bold text-slate-900">YouTube</h4>
                    <p className="text-[10px] text-slate-500">@alexchannel</p>
                  </div>
                </div>
                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                  <CheckCircle2 className="h-3 w-3" />
                  Ready
                </span>
              </div>
              <div className="text-xs text-slate-600 space-y-1.5 pt-2 border-t border-slate-100">
                <div className="flex justify-between">
                  <span className="text-slate-400">Visibility:</span>
                  <span className="font-semibold text-slate-800">Public</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">Category:</span>
                  <span className="text-slate-800">Science &amp; Tech (28)</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">COPPA:</span>
                  <span className="text-slate-800">Not Made for Kids</span>
                </div>
              </div>
            </div>

            {/* TikTok Card */}
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-black text-white text-xs font-bold">
                    TT
                  </div>
                  <div>
                    <h4 className="text-xs font-bold text-slate-900">TikTok</h4>
                    <p className="text-[10px] text-slate-500">@tiktokcreator</p>
                  </div>
                </div>
                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                  <CheckCircle2 className="h-3 w-3" />
                  Ready
                </span>
              </div>
              <div className="text-xs text-slate-600 space-y-1.5 pt-2 border-t border-slate-100">
                <div className="flex justify-between">
                  <span className="text-slate-400">Privacy:</span>
                  <span className="font-semibold text-slate-800">Public</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">Comments:</span>
                  <span className="text-emerald-600 font-semibold">Enabled</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">Duet &amp; Stitch:</span>
                  <span className="text-emerald-600 font-semibold">Allowed</span>
                </div>
              </div>
            </div>

            {/* Instagram Card */}
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-pink-600 text-white text-xs font-bold">
                    IG
                  </div>
                  <div>
                    <h4 className="text-xs font-bold text-slate-900">Instagram</h4>
                    <p className="text-[10px] text-slate-500">@john_official</p>
                  </div>
                </div>
                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                  <CheckCircle2 className="h-3 w-3" />
                  Ready
                </span>
              </div>
              <div className="text-xs text-slate-600 space-y-1.5 pt-2 border-t border-slate-100">
                <div className="flex justify-between">
                  <span className="text-slate-400">Type:</span>
                  <span className="font-semibold text-slate-800">Instagram Reel</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">Share to Feed:</span>
                  <span className="text-emerald-600 font-semibold">Yes</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">Captions &amp; Tags:</span>
                  <span className="text-slate-800">Compliant</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Bottom Actions Bar */}
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm flex items-center justify-between">
          <div className="flex items-center gap-2 text-xs text-slate-500">
            <ShieldCheck className="h-4 w-4 text-emerald-600" />
            <span>All 3 destinations passed capability validation checks.</span>
          </div>

          <div className="flex items-center gap-3">
            <Link
              href="/calendar"
              className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
            >
              <Calendar className="h-3.5 w-3.5 text-slate-500" />
              <span>Schedule for Later</span>
            </Link>

            <button
              type="button"
              onClick={handlePublish}
              disabled={isPublishing}
              className="flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
            >
              <Send className="h-3.5 w-3.5" />
              <span>Publish Now</span>
            </button>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
