'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import {
  BarChart3,
  TrendingUp,
  Eye,
  Heart,
  MessageSquare,
  Share2,
  Calendar,
  Layers,
  ArrowUpRight,
  ExternalLink,
  ChevronDown
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function AnalyticsPage() {
  const [dateRange, setDateRange] = useState('Last 7 days');

  const contentPerformance = [
    {
      id: 'cnt-1',
      title: 'How We Scaled OmniPublish from 0 to 10k Users',
      platform: 'YouTube',
      platformBadge: 'bg-red-600',
      views: '52.4K',
      likes: '3.8K',
      comments: '412',
      shares: '184',
      updated: '1 hour ago',
    },
    {
      id: 'cnt-1',
      title: 'How We Scaled OmniPublish from 0 to 10k Users',
      platform: 'TikTok',
      platformBadge: 'bg-black',
      views: '46.2K',
      likes: '2.9K',
      comments: '630',
      shares: '315',
      updated: '2 hours ago',
    },
    {
      id: 'cnt-1',
      title: 'How We Scaled OmniPublish from 0 to 10k Users',
      platform: 'Instagram',
      platformBadge: 'bg-pink-600',
      views: '25.9K',
      likes: '1.7K',
      comments: '158',
      shares: '122',
      updated: '3 hours ago',
    },
    {
      id: 'cnt-3',
      title: 'Design Tips 2026: Micro-animations in SaaS Products',
      platform: 'TikTok',
      platformBadge: 'bg-black',
      views: '68.1K',
      likes: '5.2K',
      comments: '890',
      shares: '520',
      updated: 'Yesterday',
    },
  ];

  return (
    <AppShell pageTitle="Performance Overview">
      <div className="space-y-6">
        {/* Header matching Screenshot 11 */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Performance Overview</h1>
            <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
              Aggregated and per-platform analytics synchronized directly from official APIs.
            </p>
          </div>

          <div className="flex items-center gap-2">
            {(['Last 7 days', 'Last 30 days', 'Last 90 days'] as const).map((range) => (
              <button
                key={range}
                onClick={() => setDateRange(range)}
                className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                  dateRange === range
                    ? 'bg-blue-600 text-white font-semibold shadow-sm'
                    : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'
                }`}
              >
                {range}
              </button>
            ))}
          </div>
        </div>

        {/* 4 KPI Cards matching Screenshot 11 */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Total Views */}
          <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-1">
            <span className="text-xs font-medium text-slate-500">Total Views</span>
            <div className="flex items-baseline gap-2">
              <span className="text-2xl font-bold text-slate-900">124.5K</span>
              <span className="text-xs font-semibold text-emerald-600">+12.4%</span>
            </div>
            <p className="text-[11px] text-slate-400">vs. previous period</p>
          </div>

          {/* Likes */}
          <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-1">
            <span className="text-xs font-medium text-slate-500">Likes</span>
            <div className="flex items-baseline gap-2">
              <span className="text-2xl font-bold text-slate-900">8.4K</span>
              <span className="text-xs font-semibold text-emerald-600">+8.2%</span>
            </div>
            <p className="text-[11px] text-slate-400">vs. previous period</p>
          </div>

          {/* Comments */}
          <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-1">
            <span className="text-xs font-medium text-slate-500">Comments</span>
            <div className="flex items-baseline gap-2">
              <span className="text-2xl font-bold text-slate-900">1.2K</span>
              <span className="text-xs font-semibold text-emerald-600">+5.0%</span>
            </div>
            <p className="text-[11px] text-slate-400">vs. previous period</p>
          </div>

          {/* Shares */}
          <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-1">
            <span className="text-xs font-medium text-slate-500">Shares</span>
            <div className="flex items-baseline gap-2">
              <span className="text-2xl font-bold text-slate-900">621</span>
              <span className="text-xs font-semibold text-emerald-600">+11.7%</span>
            </div>
            <p className="text-[11px] text-slate-400">vs. previous period</p>
          </div>
        </div>

        {/* Charts Grid: Views Over Time + Platform Breakdown (Screenshot 11) */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          {/* Views Chart (8 cols) */}
          <div className="lg:col-span-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div>
                <h3 className="text-sm font-bold text-slate-900">Views Over Time</h3>
                <p className="text-xs text-slate-400">Daily breakdown by platform</p>
              </div>

              <div className="flex items-center gap-3 text-xs">
                <span className="flex items-center gap-1.5 text-slate-600">
                  <span className="h-2 w-2 rounded-full bg-red-500" />
                  YouTube
                </span>
                <span className="flex items-center gap-1.5 text-slate-600">
                  <span className="h-2 w-2 rounded-full bg-black" />
                  TikTok
                </span>
                <span className="flex items-center gap-1.5 text-slate-600">
                  <span className="h-2 w-2 rounded-full bg-pink-500" />
                  Instagram
                </span>
              </div>
            </div>

            {/* SVG Interactive Multi-Line Curve Graph */}
            <div className="relative h-64 w-full">
              <svg className="h-full w-full overflow-visible" viewBox="0 0 700 220" preserveAspectRatio="none">
                {/* Horizontal Guide Lines */}
                <line x1="0" y1="20" x2="700" y2="20" stroke="#F1F5F9" strokeWidth="1" />
                <line x1="0" y1="80" x2="700" y2="80" stroke="#F1F5F9" strokeWidth="1" />
                <line x1="0" y1="140" x2="700" y2="140" stroke="#F1F5F9" strokeWidth="1" />
                <line x1="0" y1="200" x2="700" y2="200" stroke="#E2E8F0" strokeWidth="1" />

                {/* Line 1: YouTube (Red) */}
                <path
                  d="M0,170 C100,150 180,90 280,110 C380,130 480,50 580,70 L700,30"
                  fill="none"
                  stroke="#EF4444"
                  strokeWidth="2.5"
                  strokeLinecap="round"
                />

                {/* Line 2: TikTok (Black) */}
                <path
                  d="M0,190 C120,160 220,120 320,140 C420,160 520,70 620,90 L700,45"
                  fill="none"
                  stroke="#0F172A"
                  strokeWidth="2.5"
                  strokeLinecap="round"
                />

                {/* Line 3: Instagram (Pink) */}
                <path
                  d="M0,185 C110,175 210,140 310,155 C410,170 510,120 610,110 L700,85"
                  fill="none"
                  stroke="#EC4899"
                  strokeWidth="2.5"
                  strokeLinecap="round"
                />
              </svg>

              {/* X Axis Labels */}
              <div className="flex justify-between text-[11px] text-slate-400 pt-2 font-mono">
                <span>Mon</span>
                <span>Tue</span>
                <span>Wed</span>
                <span>Thu</span>
                <span>Fri</span>
                <span>Sat</span>
                <span>Sun</span>
              </div>
            </div>
          </div>

          {/* Platform Breakdown Card (Screenshot 11) (4 cols) */}
          <div className="lg:col-span-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
            <h3 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-3">
              Platform Breakdown
            </h3>

            <div className="space-y-4">
              {[
                { name: 'YouTube', color: 'bg-red-500', value: '52.6K', pct: '41%' },
                { name: 'TikTok', color: 'bg-black', value: '46.2K', pct: '36%' },
                { name: 'Instagram', color: 'bg-pink-500', value: '28.1K', pct: '21%' },
                { name: 'Facebook', color: 'bg-blue-600', value: '12.9K', pct: '10%' },
              ].map((item) => (
                <div key={item.name} className="space-y-1.5">
                  <div className="flex justify-between text-xs font-semibold">
                    <span className="flex items-center gap-2 text-slate-700">
                      <span className={`h-2.5 w-2.5 rounded-full ${item.color}`} />
                      {item.name}
                    </span>
                    <span className="text-slate-900 font-mono">
                      {item.value} <span className="text-slate-400 font-normal">({item.pct})</span>
                    </span>
                  </div>
                  <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                    <div
                      className={`h-full ${item.color} rounded-full`}
                      style={{ width: item.pct }}
                    />
                  </div>
                </div>
              ))}
            </div>

            <div className="pt-2 border-t border-slate-100 text-[11px] text-slate-400">
              Metrics queried via official platform webhooks and OAuth APIs.
            </div>
          </div>
        </div>

        {/* Content Performance Table matching Screenshot 17 */}
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <h3 className="text-sm font-bold text-slate-900">Content Performance</h3>
            <span className="text-xs text-slate-400">All Platforms</span>
          </div>

          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
              <tr>
                <th className="px-6 py-3">Content Item</th>
                <th className="px-4 py-3">Platform</th>
                <th className="px-4 py-3 text-right">Views</th>
                <th className="px-4 py-3 text-right">Likes</th>
                <th className="px-4 py-3 text-right">Comments</th>
                <th className="px-4 py-3 text-right">Shares</th>
                <th className="px-4 py-3 text-right">Updated</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {contentPerformance.map((item, idx) => (
                <tr key={idx} className="hover:bg-slate-50/70">
                  <td className="px-6 py-3 font-semibold text-slate-900">
                    <Link href={`/analytics/content/${item.id}`} className="hover:text-blue-600">
                      {item.title}
                    </Link>
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-block rounded px-2 py-0.5 text-[10px] font-bold text-white ${item.platformBadge}`}
                    >
                      {item.platform}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right font-mono font-semibold text-slate-900">
                    {item.views}
                  </td>
                  <td className="px-4 py-3 text-right font-mono text-slate-600">{item.likes}</td>
                  <td className="px-4 py-3 text-right font-mono text-slate-600">{item.comments}</td>
                  <td className="px-4 py-3 text-right font-mono text-slate-600">{item.shares}</td>
                  <td className="px-4 py-3 text-right text-slate-400">{item.updated}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </AppShell>
  );
}
