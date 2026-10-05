'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  LayoutDashboard,
  PenTool,
  Film,
  Calendar,
  Activity,
  Share2,
  BarChart3,
  Users,
  Settings,
  Bell,
  Sparkles,
  Layers,
  ChevronRight
} from 'lucide-react';
import { useAuth } from '@/context/AuthContext';

interface SidebarProps {
  isCollapsed?: boolean;
  onToggleCollapse?: () => void;
  mobileOpen?: boolean;
  onCloseMobile?: () => void;
}

interface NavItem {
  name: string;
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  badge?: string;
}

interface NavSection {
  title?: string;
  items: NavItem[];
}

const NAV_SECTIONS: NavSection[] = [
  {
    title: 'Publishing',
    items: [
      { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
      { name: 'Composer', href: '/composer', icon: PenTool },
      { name: 'Calendar', href: '/calendar', icon: Calendar },
      { name: 'Content Library', href: '/content', icon: Film },
      { name: 'Publishing Monitor', href: '/publishing', icon: Activity },
    ],
  },
  {
    title: 'Workspace',
    items: [
      { name: 'Social Channels', href: '/connections', icon: Share2 },
      { name: 'Analytics', href: '/analytics', icon: BarChart3 },
      { name: 'Team Members', href: '/team', icon: Users },
    ],
  },
  {
    title: 'Account',
    items: [
      { name: 'Settings', href: '/settings/workspace', icon: Settings },
    ],
  },
];

export function Sidebar({ isCollapsed, mobileOpen, onCloseMobile }: SidebarProps) {
  const pathname = usePathname();
  const { user, workspace } = useAuth();
  const userName = user?.name || 'Alex Vance';
  const userEmail = user?.email || 'alex@omnipublish.io';
  const userAvatar = user?.avatar || 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=150';
  const workspaceName = workspace?.name || 'OmniPublish Studio';

  const sidebarContent = (
    <div className="flex h-full flex-col justify-between bg-white border-r border-slate-200/80">
      {/* Brand Header */}
      <div className="flex flex-col flex-1 min-h-0">
        <div className="h-16 flex items-center justify-between px-5 border-b border-slate-100">
          <Link href="/dashboard" className="flex items-center gap-2.5 group">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-900 text-white shadow-2xs group-hover:bg-indigo-600 transition-colors">
              <Layers className="h-4 w-4" />
            </div>
            {!isCollapsed && (
              <div className="flex flex-col">
                <span className="font-bold text-sm tracking-tight text-slate-900 leading-none">
                  OmniPublish
                </span>
                <span className="text-[10px] text-slate-400 font-medium tracking-wide mt-1">
                  Social Studio
                </span>
              </div>
            )}
          </Link>
        </div>

        {/* Navigation Sections */}
        <div className="flex-1 overflow-y-auto px-3.5 py-4 space-y-6">
          {NAV_SECTIONS.map((section, idx) => (
            <div key={section.title || idx} className="space-y-1">
              {section.title && !isCollapsed && (
                <p className="px-3 pb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                  {section.title}
                </p>
              )}

              {section.items.map((item) => {
                const Icon = item.icon;
                const isActive =
                  pathname === item.href ||
                  (item.href === '/dashboard' && pathname === '/') ||
                  (item.href !== '/dashboard' && pathname.startsWith(item.href));

                return (
                  <Link
                    key={item.name}
                    href={item.href}
                    onClick={onCloseMobile}
                    className={`group flex items-center justify-between rounded-xl px-3 py-2 text-xs font-medium transition-all ${
                      isActive
                        ? 'bg-slate-900 text-white shadow-2xs font-semibold'
                        : 'text-slate-600 hover:bg-slate-100/70 hover:text-slate-900'
                    }`}
                    title={isCollapsed ? item.name : undefined}
                  >
                    <div className="flex items-center gap-2.5 min-w-0">
                      <Icon
                        className={`h-4 w-4 shrink-0 transition-colors ${
                          isActive ? 'text-white' : 'text-slate-400 group-hover:text-slate-700'
                        }`}
                      />
                      {!isCollapsed && <span className="truncate">{item.name}</span>}
                    </div>

                    {!isCollapsed && isActive && (
                      <span className="h-1.5 w-1.5 rounded-full bg-indigo-400" />
                    )}
                  </Link>
                );
              })}
            </div>
          ))}
        </div>
      </div>

      {/* Bottom Profile Footer */}
      <div className="p-3 border-t border-slate-100">
        <Link
          href="/settings/profile"
          className="flex items-center gap-2.5 rounded-xl p-2 text-slate-700 hover:bg-slate-50 transition-colors group"
        >
          <div className="h-8 w-8 rounded-xl bg-slate-900 text-white flex items-center justify-center text-xs font-bold shrink-0 shadow-2xs">
            {userName.split(' ').map((n) => n[0]).join('').toUpperCase().slice(0, 2)}
          </div>
          {!isCollapsed && (
            <div className="flex-1 min-w-0">
              <p className="text-xs font-semibold text-slate-900 truncate group-hover:text-indigo-600 transition-colors">
                {userName}
              </p>
              <p className="text-[11px] text-slate-400 truncate">{workspaceName}</p>
            </div>
          )}
        </Link>
      </div>
    </div>
  );

  return (
    <>
      {/* Desktop Sidebar */}
      <aside
        className={`hidden md:block shrink-0 transition-all duration-200 ${
          isCollapsed ? 'w-16' : 'w-56'
        } h-screen sticky top-0`}
      >
        {sidebarContent}
      </aside>

      {/* Mobile Drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 md:hidden flex">
          <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-xs" onClick={onCloseMobile} />
          <div className="relative w-60 max-w-[80vw] h-full shadow-2xl z-10">
            {sidebarContent}
          </div>
        </div>
      )}
    </>
  );
}
