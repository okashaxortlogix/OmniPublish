'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  Search,
  Plus,
  Bell,
  Menu
} from 'lucide-react';
import { useAuth } from '@/context/AuthContext';

interface TopBarProps {
  onOpenMobile?: () => void;
  pageTitle?: string;
}

export function TopBar({ onOpenMobile, pageTitle }: TopBarProps) {
  const pathname = usePathname();
  const { user, workspace } = useAuth();
  const userName = user?.name || 'Alex Vance';
  const workspaceName = workspace?.name || 'OmniPublish Studio';

  const initials = userName
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);

  return (
    <header className="sticky top-0 z-30 flex h-16 w-full items-center justify-between border-b border-slate-200/80 bg-white/95 backdrop-blur-xs px-5 sm:px-8">
      {/* Left: Mobile Toggle & Page Title */}
      <div className="flex items-center gap-3">
        <button
          onClick={onOpenMobile}
          className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 md:hidden"
          aria-label="Open navigation menu"
        >
          <Menu className="h-5 w-5" />
        </button>

        {pageTitle && (
          <h1 className="text-sm font-semibold text-slate-900 tracking-tight">
            {pageTitle}
          </h1>
        )}
      </div>

      {/* Right: Workspace Tag, Search, Action Button, Notifications & Avatar */}
      <div className="flex items-center gap-3">
        {/* Minimal Search Bar */}
        <div className="relative hidden md:block w-48 lg:w-60">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-400" />
          <input
            type="text"
            placeholder="Search... ⌘K"
            className="w-full rounded-xl border border-slate-200/80 bg-slate-50/60 py-1.5 pl-8 pr-3 text-xs text-slate-700 placeholder-slate-400 focus:bg-white focus:outline-none focus:border-slate-400 focus:ring-1 focus:ring-slate-400 transition-all"
          />
        </div>

        {/* Minimal Workspace Chip */}
        <div className="hidden lg:flex items-center gap-1.5 rounded-xl border border-slate-200/70 bg-slate-50/50 px-2.5 py-1.5 text-xs text-slate-600">
          <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
          <span className="font-medium truncate max-w-[130px]">{workspaceName}</span>
        </div>

        {/* Create Post Button - Hidden when already on composer */}
        {pathname !== '/composer' && (
          <Link
            href="/composer"
            className="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-1.5 text-xs font-semibold text-white shadow-2xs hover:bg-slate-800 active:scale-95 transition-all"
          >
            <Plus className="h-3.5 w-3.5 text-indigo-400" />
            <span>New Post</span>
          </Link>
        )}

        {/* Notifications Icon with subtle indicator */}
        <Link
          href="/notifications"
          className="relative p-2 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-100/70 transition-colors"
          title="Notifications"
        >
          <Bell className="h-4 w-4" />
          <span className="absolute top-1.5 right-1.5 h-1.5 w-1.5 rounded-full bg-indigo-600 ring-2 ring-white" />
        </Link>

        {/* User Account Avatar */}
        <Link
          href="/settings/profile"
          className="flex items-center p-0.5 rounded-full hover:ring-2 hover:ring-slate-300 transition-all"
          title={userName}
        >
          <div className="h-7 w-7 rounded-full bg-slate-900 text-white flex items-center justify-center text-[11px] font-bold shadow-2xs">
            {initials}
          </div>
        </Link>
      </div>
    </header>
  );
}
