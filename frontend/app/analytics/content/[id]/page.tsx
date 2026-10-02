'use client';

import React from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  ArrowLeft,
  BarChart3,
  TrendingUp,
  Eye,
  Heart,
  MessageSquare,
  Share2,
  ExternalLink,
  Play
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function DetailedAnalyticsPage() {
  const params = useParams();
  const id = params?.id || 'cnt-1';

  return (
    <AppShell pageTitle="Detailed Content Analytics">
      <div className="max-w-5xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between border-b border-slate-200 pb-4">
          <div className="flex items-center gap-3">
            <Link
              href="/analytics"
              className="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-600"
            >
              <ArrowLeft className="h-4 w-4" />
            </Link>
            <div>
              <h1 className="text-xl font-bold text-slate-900">
                Detailed Analytics: How We Scaled OmniPublish from 0 to 10k Users
              </h1>
              <p className="text-xs text-slate-500">Asset ID: {id} • Realtime API telemetry</p>
            </div>
          </div>
        </div>

        {/* Video Summary Strip */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm flex items-center gap-5">
          <div className="relative aspect-video w-36 rounded-lg overflow-hidden bg-slate-900 shrink-0">
            <img
              src="https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=400"
              alt="Preview"
              className="w-full h-full object-cover opacity-90"
            />
          </div>
          <div className="space-y-1">
            <h3 className="text-sm font-bold text-slate-900">
              How We Scaled OmniPublish from 0 to 10k Users
            </h3>
            <div className="flex items-center gap-3 text-xs text-slate-500">
              <span>Duration: 01:45</span>
              <span>•</span>
              <span>Published to 3 platforms</span>
              <span>•</span>
              <span className="font-semibold text-emerald-600">124.5K total views</span>
            </div>
          </div>
        </div>

        {/* Detailed Breakdown Table matching Screenshot 17 */}
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div className="border-b border-slate-200 px-6 py-4">
            <h3 className="text-sm font-bold text-slate-900">Per-Platform Engagement Breakdown</h3>
          </div>

          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
              <tr>
                <th className="px-6 py-3">Platform</th>
                <th className="px-4 py-3">Account</th>
                <th className="px-4 py-3 text-right">Views</th>
                <th className="px-4 py-3 text-right">Likes</th>
                <th className="px-4 py-3 text-right">Comments</th>
                <th className="px-4 py-3 text-right">Shares</th>
                <th className="px-4 py-3 text-right">Last Sync</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              <tr className="hover:bg-slate-50">
                <td className="px-6 py-3.5">
                  <span className="inline-block rounded bg-red-600 px-2 py-0.5 text-[10px] font-bold text-white">
                    YouTube
                  </span>
                </td>
                <td className="px-4 py-3.5 font-medium text-slate-700">@alexchannel</td>
                <td className="px-4 py-3.5 text-right font-mono font-bold text-slate-900">52,410</td>
                <td className="px-4 py-3.5 text-right font-mono text-slate-700">3,820</td>
                <td className="px-4 py-3.5 text-right font-mono text-slate-700">412</td>
                <td className="px-4 py-3.5 text-right font-mono text-slate-700">184</td>
                <td className="px-4 py-3.5 text-right text-slate-400">10 mins ago</td>
              </tr>
              <tr className="hover:bg-slate-50">
                <td className="px-6 py-3.5">
                  <span className="inline-block rounded bg-black px-2 py-0.5 text-[10px] font-bold text-white">
                    TikTok
                  </span>
                </td>
                <td className="px-4 py-3.5 font-medium text-slate-700">@tiktokcreator</td>
                <td className="px-4 py-3.5 text-right font-mono font-bold text-slate-900">46,200</td>
                <td className="px-4 py-3.5 text-right font-mono text-slate-700">2,910</td>
                <td className="px-4 py-3.5 text-right font-mono text-slate-700">630</td>
                <td className="px-4 py-3.5 text-right font-mono text-slate-700">315</td>
                <td className="px-4 py-3.5 text-right text-slate-400">15 mins ago</td>
              </tr>
              <tr className="hover:bg-slate-50">
                <td className="px-6 py-3.5">
                  <span className="inline-block rounded bg-pink-600 px-2 py-0.5 text-[10px] font-bold text-white">
                    Instagram
                  </span>
                </td>
                <td className="px-4 py-3.5 font-medium text-slate-700">@john_official</td>
                <td className="px-4 py-3.5 text-right font-mono font-bold text-slate-900">25,890</td>
                <td className="px-4 py-3.5 text-right font-mono text-slate-700">1,670</td>
                <td className="px-4 py-3.5 text-right font-mono text-slate-700">158</td>
                <td className="px-4 py-3.5 text-right font-mono text-slate-700">122</td>
                <td className="px-4 py-3.5 text-right text-slate-400">25 mins ago</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </AppShell>
  );
}
