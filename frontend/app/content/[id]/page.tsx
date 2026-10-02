'use client';

import React from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  ArrowLeft,
  Play,
  Share2,
  CheckCircle2,
  AlertCircle,
  ExternalLink,
  Clock,
  RotateCcw,
  Shield,
  Layers,
  Calendar,
  User,
  History
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function ContentDetailPage() {
  const params = useParams();
  const id = params?.id || 'cnt-1';

  return (
    <AppShell pageTitle="Content Asset Detail">
      <div className="max-w-5xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
          <div className="flex items-center gap-3">
            <Link
              href="/content"
              className="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-600"
            >
              <ArrowLeft className="h-4 w-4" />
            </Link>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-xl font-bold text-slate-900">
                  How We Scaled OmniPublish from 0 to 10k Users
                </h1>
                <span className="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                  Published
                </span>
              </div>
              <p className="text-xs text-slate-500 mt-0.5">Asset ID: {id} • Created April 28, 2026</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Link
              href="/composer"
              className="rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
            >
              Republish / Edit in Composer
            </Link>
          </div>
        </div>

        {/* Media Preview & Metadata Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          {/* Video Player Card (6 cols) */}
          <div className="lg:col-span-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
            <div className="relative aspect-video rounded-lg overflow-hidden bg-slate-900 shadow-inner group">
              <img
                src="https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=700"
                alt="Video Master"
                className="w-full h-full object-cover opacity-90"
              />
              <div className="absolute inset-0 bg-black/20 flex items-center justify-center">
                <div className="h-12 w-12 rounded-full bg-white/95 text-slate-900 flex items-center justify-center shadow-lg group-hover:scale-105 transition-transform">
                  <Play className="h-6 w-6 fill-slate-900 ml-0.5" />
                </div>
              </div>
              <span className="absolute bottom-3 right-3 rounded bg-black/80 px-2 py-0.5 text-xs font-mono text-white">
                01:45
              </span>
            </div>

            <div className="grid grid-cols-4 gap-2 text-center text-xs pt-1 border-t border-slate-100">
              <div className="p-2 rounded bg-slate-50">
                <p className="text-slate-400 text-[10px]">Format</p>
                <p className="font-semibold text-slate-800">MP4 (H.264)</p>
              </div>
              <div className="p-2 rounded bg-slate-50">
                <p className="text-slate-400 text-[10px]">Resolution</p>
                <p className="font-semibold text-slate-800">1920x1080</p>
              </div>
              <div className="p-2 rounded bg-slate-50">
                <p className="text-slate-400 text-[10px]">File Size</p>
                <p className="font-semibold text-slate-800">48.2 MB</p>
              </div>
              <div className="p-2 rounded bg-slate-50">
                <p className="text-slate-400 text-[10px]">Aspect</p>
                <p className="font-semibold text-slate-800">16:9</p>
              </div>
            </div>
          </div>

          {/* Metadata Card (6 cols) */}
          <div className="lg:col-span-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
            <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
              Asset Metadata
            </h3>

            <div className="space-y-3 text-xs">
              <div>
                <span className="text-slate-400 block mb-0.5">Master Title</span>
                <p className="font-semibold text-slate-900">
                  How We Scaled OmniPublish from 0 to 10k Users
                </p>
              </div>

              <div>
                <span className="text-slate-400 block mb-0.5">Description</span>
                <p className="text-slate-700 leading-relaxed">
                  A full technical breakdown of multi-platform video publishing, worker queues, and resilient social APIs.
                </p>
              </div>

              <div>
                <span className="text-slate-400 block mb-1">Hashtags</span>
                <div className="flex flex-wrap gap-1.5">
                  {['#omnipublish', '#saas', '#tech', '#coding', '#scale'].map((h) => (
                    <span
                      key={h}
                      className="rounded bg-blue-50 px-2 py-0.5 text-blue-700 font-medium"
                    >
                      {h}
                    </span>
                  ))}
                </div>
              </div>

              <div>
                <span className="text-slate-400 block mb-1">Tags</span>
                <div className="flex flex-wrap gap-1.5">
                  {['SaaS', 'Startup', 'Engineering', 'Architecture'].map((t) => (
                    <span
                      key={t}
                      className="rounded bg-slate-100 px-2 py-0.5 text-slate-700 font-medium"
                    >
                      {t}
                    </span>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Publishing Destination History (Section 12) */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
          <h3 className="text-sm font-bold text-slate-900">Publishing Destination Attempts</h3>

          <div className="space-y-3">
            {[
              {
                platform: 'YouTube',
                icon: 'YT',
                badgeBg: 'bg-red-600',
                account: '@alexchannel',
                status: 'Published',
                statusBadge: 'bg-emerald-50 text-emerald-700',
                publishedAt: 'Apr 28, 2026 10:24 AM',
                url: 'https://youtube.com/watch?v=demo_scaling',
              },
              {
                platform: 'TikTok',
                icon: 'TT',
                badgeBg: 'bg-black',
                account: '@tiktokcreator',
                status: 'Published',
                statusBadge: 'bg-emerald-50 text-emerald-700',
                publishedAt: 'Apr 28, 2026 10:25 AM',
                url: 'https://tiktok.com/@tiktokcreator/video/987123',
              },
              {
                platform: 'Instagram',
                icon: 'IG',
                badgeBg: 'bg-pink-600',
                account: '@john_official',
                status: 'Published',
                statusBadge: 'bg-emerald-50 text-emerald-700',
                publishedAt: 'Apr 28, 2026 10:26 AM',
                url: 'https://instagram.com/reel/C892182',
              },
            ].map((p, idx) => (
              <div
                key={idx}
                className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-lg border border-slate-200 p-3.5 hover:bg-slate-50/50"
              >
                <div className="flex items-center gap-3">
                  <div
                    className={`flex h-8 w-8 items-center justify-center rounded text-white text-xs font-bold ${p.badgeBg}`}
                  >
                    {p.icon}
                  </div>
                  <div>
                    <h4 className="text-xs font-bold text-slate-900">{p.platform}</h4>
                    <p className="text-[11px] text-slate-500">{p.account}</p>
                  </div>
                </div>

                <div className="flex items-center gap-4 text-xs">
                  <span className="text-slate-400">{p.publishedAt}</span>

                  <span
                    className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${p.statusBadge}`}
                  >
                    {p.status}
                  </span>

                  <a
                    href={p.url}
                    target="_blank"
                    rel="noreferrer"
                    className="flex items-center gap-1 font-semibold text-blue-600 hover:underline"
                  >
                    <span>View Post</span>
                    <ExternalLink className="h-3 w-3" />
                  </a>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Audit Log History (Section 12) */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
          <div className="flex items-center gap-2">
            <History className="h-4 w-4 text-slate-500" />
            <h3 className="text-sm font-bold text-slate-900">Audit Trail</h3>
          </div>

          <div className="border-l-2 border-slate-200 ml-3 pl-4 space-y-4 text-xs">
            <div className="relative">
              <span className="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-4 ring-white" />
              <p className="font-semibold text-slate-900">Published to YouTube, TikTok, Instagram</p>
              <p className="text-slate-500 text-[11px]">Apr 28, 2026, 10:26 AM by System Worker Queue</p>
            </div>

            <div className="relative">
              <span className="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full bg-blue-500 ring-4 ring-white" />
              <p className="font-semibold text-slate-900">Publish Dispatched (Batch #OP-2026-00042)</p>
              <p className="text-slate-500 text-[11px]">Apr 28, 2026, 10:24 AM by John Doe</p>
            </div>

            <div className="relative">
              <span className="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full bg-slate-400 ring-4 ring-white" />
              <p className="font-semibold text-slate-900">Master Video Asset Uploaded</p>
              <p className="text-slate-500 text-[11px]">Apr 28, 2026, 10:20 AM by John Doe</p>
            </div>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
