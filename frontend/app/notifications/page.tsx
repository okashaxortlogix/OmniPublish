'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import {
  Bell,
  CheckCircle2,
  AlertCircle,
  Users,
  Shield,
  Activity,
  Check,
  ExternalLink
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

interface NotificationItem {
  id: string;
  category: 'Publishing' | 'Account' | 'Team' | 'Security';
  title: string;
  description: string;
  timestamp: string;
  read: boolean;
  link?: string;
}

export default function NotificationsPage() {
  const [activeCategory, setActiveCategory] = useState<string>('All');
  const [notifications, setNotifications] = useState<NotificationItem[]>([
    {
      id: '1',
      category: 'Publishing',
      title: 'Your video has been published to YouTube',
      description: 'How We Scaled OmniPublish from 0 to 10k Users is now live on YouTube.',
      timestamp: 'Apr 28, 2026 • 10:25 AM',
      read: false,
      link: '/publishing',
    },
    {
      id: '2',
      category: 'Account',
      title: 'Instagram authorization requires attention',
      description: 'Your Instagram OAuth token expired. Please reauthorize to resume automated publishing.',
      timestamp: 'Apr 27, 2026 • 04:12 PM',
      read: false,
      link: '/connections',
    },
    {
      id: '3',
      category: 'Team',
      title: 'New team member joined your workspace',
      description: 'Sarah Khan accepted your invitation as Admin.',
      timestamp: 'Apr 26, 2026 • 02:40 PM',
      read: true,
      link: '/team',
    },
    {
      id: '4',
      category: 'Publishing',
      title: 'Post scheduled for tomorrow',
      description: 'Python Tutorial #12 is scheduled for broadcast across YouTube & Facebook.',
      timestamp: 'Apr 25, 2026 • 01:15 PM',
      read: true,
      link: '/calendar',
    },
  ]);

  const markAllAsRead = () => {
    setNotifications(notifications.map((n) => ({ ...n, read: true })));
  };

  const toggleRead = (id: string) => {
    setNotifications(
      notifications.map((n) => (n.id === id ? { ...n, read: !n.read } : n))
    );
  };

  const filtered = activeCategory === 'All'
    ? notifications
    : notifications.filter((n) => n.category === activeCategory);

  return (
    <AppShell pageTitle="Notifications">
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Header matching Screenshot 15 */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Notifications</h1>
            <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
              Stay updated on publishing dispatches, team events, and account health.
            </p>
          </div>

          <button
            onClick={markAllAsRead}
            className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-sm transition-colors"
          >
            <Check className="h-3.5 w-3.5" />
            <span>Mark all as read</span>
          </button>
        </div>

        {/* Categories Tabs */}
        <div className="flex border-b border-slate-200 bg-white rounded-t-xl px-4 gap-2">
          {['All', 'Publishing', 'Account', 'Team', 'Security'].map((cat) => (
            <button
              key={cat}
              onClick={() => setActiveCategory(cat)}
              className={`py-3 px-3 text-xs font-semibold border-b-2 transition-colors ${
                activeCategory === cat
                  ? 'border-blue-600 text-blue-600'
                  : 'border-transparent text-slate-500 hover:text-slate-800'
              }`}
            >
              {cat}
            </button>
          ))}
        </div>

        {/* Notifications List matching Screenshot 15 */}
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden divide-y divide-slate-100">
          {filtered.map((item) => (
            <div
              key={item.id}
              className={`flex items-start justify-between gap-4 p-5 hover:bg-slate-50/70 transition-colors ${
                !item.read ? 'bg-blue-50/20' : ''
              }`}
            >
              <div className="flex items-start gap-3.5">
                <div
                  className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
                    item.category === 'Publishing'
                      ? 'bg-blue-50 text-blue-600'
                      : item.category === 'Account'
                      ? 'bg-amber-50 text-amber-600'
                      : item.category === 'Team'
                      ? 'bg-purple-50 text-purple-600'
                      : 'bg-emerald-50 text-emerald-600'
                  }`}
                >
                  {item.category === 'Publishing' ? (
                    <Activity className="h-5 w-5" />
                  ) : item.category === 'Account' ? (
                    <AlertCircle className="h-5 w-5" />
                  ) : item.category === 'Team' ? (
                    <Users className="h-5 w-5" />
                  ) : (
                    <Shield className="h-5 w-5" />
                  )}
                </div>

                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <h4 className="text-xs font-bold text-slate-900">{item.title}</h4>
                    {!item.read && (
                      <span className="h-2 w-2 rounded-full bg-blue-600" />
                    )}
                  </div>
                  <p className="text-xs text-slate-600">{item.description}</p>
                  <p className="text-[11px] text-slate-400 pt-0.5">{item.timestamp}</p>
                </div>
              </div>

              <div className="flex items-center gap-2 shrink-0">
                {item.link && (
                  <Link
                    href={item.link}
                    className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-blue-600 hover:bg-slate-50"
                  >
                    View
                  </Link>
                )}
                <button
                  onClick={() => toggleRead(item.id)}
                  className="p-1 rounded text-slate-400 hover:text-slate-600 text-xs"
                  title="Toggle read status"
                >
                  {item.read ? 'Mark unread' : 'Mark read'}
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </AppShell>
  );
}
