'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  LayoutDashboard,
  Film,
  UploadCloud,
  PenTool,
  Calendar,
  Activity,
  BarChart3,
  Share2,
  Users,
  Bell,
  Settings,
  HelpCircle,
  Sparkles,
  ChevronDown,
  Layers,
  ChevronRight
} from 'lucide-react';

interface SidebarProps {
  isCollapsed?: boolean;
  onToggleCollapse?: () => void;
  mobileOpen?: boolean;
  onCloseMobile?: () => void;
}

const MAIN_NAV = [
  { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
  { name: 'Content Library', href: '/content', icon: Film },
  { name: 'Upload', href: '/upload', icon: UploadCloud },
  { name: 'Composer', href: '/composer', icon: PenTool },
  { name: 'Calendar', href: '/calendar', icon: Calendar },
  { name: 'Publishing Monitor', href: '/publishing', icon: Activity },
  { name: 'Analytics', href: '/analytics', icon: BarChart3 },
  { name: 'Connections', href: '/connections', icon: Share2 },
  { name: 'AI Assistant', href: '/ai', icon: Sparkles },
  { name: 'Team', href: '/team', icon: Users },
  { name: 'Notifications', href: '/notifications', icon: Bell },
  { name: 'Settings', href: '/settings/workspace', icon: Settings },
];

export function Sidebar({ isCollapsed, onToggleCollapse, mobileOpen, onCloseMobile }: SidebarProps) {
  const pathname = usePathname();

  const sidebarContent = (
    <div className="flex h-full flex-col justify-between bg-white border-r border-slate-200">
      {/* Top Brand & Workspace Switcher */}
      <div className="p-4 space-y-4">
        {/* Logo and Wordmark */}
        <div className="flex items-center justify-between">
          <Link href="/dashboard" className="flex items-center gap-2.5">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-600 text-white shadow-sm font-bold">
              <Layers className="h-5 w-5" />
            </div>
            {!isCollapsed && (
              <span className="font-bold text-lg text-slate-900 tracking-tight">
                Omni<span className="text-blue-600">Publish</span>
              </span>
            )}
          </Link>
        </div>

        {/* Workspace Selector Dropdown */}
        {!isCollapsed && (
          <div className="relative">
            <Link
              href="/settings/workspace"
              className="flex w-full items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs hover:bg-slate-100 transition-colors"
            >
              <div className="flex items-center gap-2 truncate">
                <div className="h-4 w-4 rounded bg-blue-600 flex items-center justify-center text-[10px] text-white font-bold">
                  O
                </div>
                <span className="font-semibold text-slate-800 truncate">OmniPublish Studio</span>
              </div>
              <ChevronDown className="h-3.5 w-3.5 text-slate-400" />
            </Link>
          </div>
        )}

        {/* Navigation Items */}
        <nav className="space-y-1 pt-1">
          {MAIN_NAV.map((item) => {
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
                className={`flex items-center gap-3 rounded-lg px-3 py-2 text-xs font-medium transition-colors ${
                  isActive
                    ? 'bg-blue-50 text-blue-600 font-semibold'
                    : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                }`}
                title={isCollapsed ? item.name : undefined}
              >
                <Icon className={`h-4 w-4 shrink-0 ${isActive ? 'text-blue-600' : 'text-slate-400'}`} />
                {!isCollapsed && <span>{item.name}</span>}
              </Link>
            );
          })}
        </nav>
      </div>

      {/* Bottom User Profile & Support */}
      <div className="p-4 border-t border-slate-100 space-y-2">
        <Link
          href="/settings/profile"
          className="flex items-center gap-3 rounded-lg p-2 hover:bg-slate-50 transition-colors text-xs"
        >
          <img
            src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150"
            alt="John Doe"
            className="h-8 w-8 rounded-full object-cover border border-slate-200"
          />
          {!isCollapsed && (
            <div className="truncate">
              <p className="font-semibold text-slate-800 truncate">John Doe</p>
              <p className="text-[11px] text-slate-500 truncate">john@company.com</p>
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
          isCollapsed ? 'w-16' : 'w-60'
        } h-screen sticky top-0`}
      >
        {sidebarContent}
      </aside>

      {/* Mobile Drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 md:hidden flex">
          <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" onClick={onCloseMobile} />
          <div className="relative w-64 max-w-[80vw] h-full shadow-2xl z-10">
            {sidebarContent}
          </div>
        </div>
      )}
    </>
  );
}
