'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import {
  Share2,
  Film,
  Sparkles,
  CheckCircle2,
  Clock,
  ArrowUpRight,
  TrendingUp,
  Plus,
  MoreVertical,
  Calendar,
  Layers,
  ChevronRight,
  Play,
  UploadCloud,
  Check
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';
import { PublishBatch, SocialAccount, AnalyticsSummary } from '@/lib/types';

import { useAuth } from '@/context/AuthContext';

export default function DashboardPage() {
  const { user } = useAuth();
  const firstName = user?.name ? user.name.split(' ')[0] : 'Creator';
  const [batches, setBatches] = useState<PublishBatch[]>([]);
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [analytics, setAnalytics] = useState<AnalyticsSummary | null>(null);

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    try {
      const [batchesRes, accountsRes, analyticsRes] = await Promise.allSettled([
        api.getPublishBatches(),
        api.getSocialAccounts(),
        api.getAnalyticsSummary(),
      ]);

      if (batchesRes.status === 'fulfilled') setBatches(batchesRes.value);
      if (accountsRes.status === 'fulfilled') setAccounts(accountsRes.value.accounts);
      if (analyticsRes.status === 'fulfilled') setAnalytics(analyticsRes.value);
    } catch (e) {
      console.error(e);
    }
  };

  return (
    <AppShell pageTitle="Dashboard">
      <div className="space-y-6">
        {/* 1. Header Greeting & Quick Actions */}
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-slate-900">
              Good morning, {firstName}!
            </h1>
            <p className="text-xs text-slate-500 mt-0.5">
              Here&apos;s what&apos;s happening with your content today.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <div className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700 shadow-sm font-medium">
              Last 7 days
            </div>
            <Link
              href="/composer"
              className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 active:scale-95 transition-all"
            >
              <Plus className="h-4 w-4" />
              <span>Create New Post</span>
            </Link>
          </div>
        </div>

        {/* 2. KPI Cards Row matching Screenshot 4 */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {/* Card 1: Connected Accounts */}
          <div className="saas-card p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-500">Connected Accounts</span>
              <div className="rounded-lg bg-blue-50 p-2 text-blue-600">
                <Share2 className="h-4 w-4" />
              </div>
            </div>
            <div className="mt-3 flex items-baseline gap-2">
              <span className="text-2xl font-bold text-slate-900">{accounts.length || 5}</span>
              <span className="text-xs font-semibold text-blue-600">+2 new</span>
            </div>
            <p className="mt-1 text-[11px] text-slate-400">across 3 active platforms</p>
          </div>

          {/* Card 2: Queued Publications */}
          <div className="saas-card p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-500">Queued Publications</span>
              <div className="rounded-lg bg-emerald-50 p-2 text-emerald-600">
                <Clock className="h-4 w-4" />
              </div>
            </div>
            <div className="mt-3 flex items-baseline gap-2">
              <span className="text-2xl font-bold text-slate-900">3</span>
              <span className="text-xs font-semibold text-emerald-600">+1 new</span>
            </div>
            <p className="mt-1 text-[11px] text-slate-400">in worker pipeline</p>
          </div>

          {/* Card 3: Today's Posts */}
          <div className="saas-card p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-500">Today&apos;s Posts</span>
              <div className="rounded-lg bg-purple-50 p-2 text-purple-600">
                <Calendar className="h-4 w-4" />
              </div>
            </div>
            <div className="mt-3 flex items-baseline gap-2">
              <span className="text-2xl font-bold text-slate-900">8</span>
              <span className="text-xs font-semibold text-purple-600">+3 scheduled</span>
            </div>
            <p className="mt-1 text-[11px] text-slate-400">ready for dispatch</p>
          </div>

          {/* Card 4: Success Rate with Ring Gauge */}
          <div className="saas-card p-5 flex items-center justify-between">
            <div>
              <span className="text-xs font-medium text-slate-500">Success Rate</span>
              <div className="mt-2 flex items-baseline gap-2">
                <span className="text-2xl font-bold text-slate-900">96%</span>
              </div>
              <p className="mt-1 text-[11px] text-emerald-600 font-medium">High Reliability</p>
            </div>
            <div className="relative h-12 w-12 flex items-center justify-center">
              <svg className="h-full w-full -rotate-90 transform" viewBox="0 0 36 36">
                <path
                  className="text-slate-100"
                  strokeWidth="3.5"
                  stroke="currentColor"
                  fill="none"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
                <path
                  className="text-emerald-500"
                  strokeDasharray="96, 100"
                  strokeWidth="3.5"
                  strokeLinecap="round"
                  stroke="currentColor"
                  fill="none"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
              </svg>
              <span className="absolute text-[10px] font-bold text-slate-700">96%</span>
            </div>
          </div>
        </div>

        {/* 3. Main Content Split: Recent Content Table (Left) + Recent Activity (Right) */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          {/* Left: Recent Content Table */}
          <div className="saas-card lg:col-span-2 overflow-hidden">
            <div className="flex items-center justify-between p-5 border-b border-slate-100">
              <h2 className="text-sm font-bold text-slate-900">Recent Content</h2>
              <Link href="/content" className="text-xs font-semibold text-blue-600 hover:underline">
                View all
              </Link>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50/75 text-[11px] font-semibold text-slate-500">
                    <th className="py-3 px-5">Title</th>
                    <th className="py-3 px-4">Platforms</th>
                    <th className="py-3 px-4">Status</th>
                    <th className="py-3 px-4">Created</th>
                    <th className="py-3 px-4 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {[
                    {
                      id: 'cnt-1',
                      title: 'How We Scaled OmniPublish from 0 to 10k Users',
                      platforms: ['YouTube', 'TikTok', 'Instagram'],
                      status: 'Published',
                      date: 'Apr 28, 2026',
                      thumb: 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=200',
                    },
                    {
                      id: 'cnt-2',
                      title: 'Python Tutorial #12: Async Queues',
                      platforms: ['YouTube', 'Facebook'],
                      status: 'Scheduled',
                      date: 'Apr 27, 2026',
                      thumb: 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?w=200',
                    },
                    {
                      id: 'cnt-3',
                      title: 'Design Tips 2026: Micro-animations in SaaS Products',
                      platforms: ['TikTok', 'Instagram'],
                      status: 'In Progress',
                      date: 'Apr 26, 2026',
                      thumb: 'https://images.unsplash.com/photo-1507238691740-187a5b1d37b8?w=200',
                    },
                    {
                      id: 'cnt-4',
                      title: 'Next.js 15 & Laravel API Fullstack Boilerplate',
                      platforms: ['YouTube', 'LinkedIn', 'X'],
                      status: 'Published',
                      date: 'Apr 25, 2026',
                      thumb: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=200',
                    },
                  ].map((item) => (
                    <tr key={item.id} className="hover:bg-slate-50/80 transition-colors">
                      <td className="py-3.5 px-5">
                        <div className="flex items-center gap-3">
                          <img
                            src={item.thumb}
                            alt=""
                            className="h-9 w-14 rounded object-cover border border-slate-200"
                          />
                          <span className="font-semibold text-slate-800 line-clamp-1">{item.title}</span>
                        </div>
                      </td>
                      <td className="py-3.5 px-4">
                        <div className="flex items-center gap-1">
                          {item.platforms.map((p) => (
                            <span
                              key={p}
                              className="rounded px-1.5 py-0.5 text-[10px] font-medium bg-slate-100 text-slate-600 border border-slate-200"
                            >
                              {p}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="py-3.5 px-4">
                        <span
                          className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                            item.status === 'Published'
                              ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                              : item.status === 'Scheduled'
                              ? 'bg-blue-50 text-blue-700 border border-blue-200'
                              : 'bg-amber-50 text-amber-700 border border-amber-200'
                          }`}
                        >
                          <span className="h-1.5 w-1.5 rounded-full bg-current" />
                          {item.status}
                        </span>
                      </td>
                      <td className="py-3.5 px-4 text-slate-500">{item.date}</td>
                      <td className="py-3.5 px-4 text-right">
                        <Link
                          href={`/content/${item.id}`}
                          className="text-slate-400 hover:text-slate-700 p-1 rounded inline-block"
                        >
                          <MoreVertical className="h-4 w-4" />
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Right: Recent Activity Timeline */}
          <div className="saas-card p-5 space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <h2 className="text-sm font-bold text-slate-900">Recent Activity</h2>
              <Link href="/settings/audit-logs" className="text-xs font-semibold text-blue-600 hover:underline">
                View all
              </Link>
            </div>

            <div className="space-y-4 text-xs">
              {[
                { title: 'Video published on YouTube', sub: 'AI Tools Explained in 60s', time: '10m ago', icon: CheckCircle2, color: 'text-emerald-500' },
                { title: 'Connection added: TikTok', sub: '@alexv_codes linked', time: '2h ago', icon: Share2, color: 'text-blue-500' },
                { title: 'Scheduled post for tomorrow', sub: 'Python Tutorial #12', time: '5h ago', icon: Calendar, color: 'text-purple-500' },
                { title: 'New team member invited', sub: 'sarah@company.com (Admin)', time: '1d ago', icon: Sparkles, color: 'text-amber-500' },
              ].map((act, idx) => {
                const Icon = act.icon;
                return (
                  <div key={idx} className="flex items-start gap-3">
                    <div className={`mt-0.5 ${act.color}`}>
                      <Icon className="h-4 w-4" />
                    </div>
                    <div className="flex-1 truncate">
                      <p className="font-semibold text-slate-800">{act.title}</p>
                      <p className="text-[11px] text-slate-500 truncate">{act.sub}</p>
                    </div>
                    <span className="text-[10px] text-slate-400">{act.time}</span>
                  </div>
                );
              })}
            </div>

            <div className="mt-4 pt-3 border-t border-slate-100">
              <Link
                href="/publishing"
                className="w-full flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors"
              >
                <span>Open Publishing Monitor</span>
                <ChevronRight className="h-3.5 w-3.5" />
              </Link>
            </div>
          </div>
        </div>

        {/* 4. Quick Actions Row */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          {[
            { label: 'Upload Video', href: '/upload', icon: UploadCloud },
            { label: 'Create Post', href: '/composer', icon: Sparkles },
            { label: 'Schedule Post', href: '/calendar', icon: Calendar },
            { label: 'Connect Platform', href: '/connections', icon: Share2 },
            { label: 'View Analytics', href: '/analytics', icon: TrendingUp },
          ].map((action) => {
            const Icon = action.icon;
            return (
              <Link
                key={action.label}
                href={action.href}
                className="saas-card p-3 flex flex-col items-center justify-center text-center gap-1.5 hover:border-blue-400 hover:shadow transition-all group"
              >
                <div className="rounded-lg bg-blue-50 p-2 text-blue-600 group-hover:bg-blue-600 group-hover:text-white transition-colors">
                  <Icon className="h-4 w-4" />
                </div>
                <span className="text-xs font-semibold text-slate-700">{action.label}</span>
              </Link>
            );
          })}
        </div>
      </div>
    </AppShell>
  );
}
