'use client';

import React from 'react';
import Link from 'next/link';
import {
  Search,
  Plus,
  Bell,
  HelpCircle,
  Menu,
  ChevronDown
} from 'lucide-react';

interface TopBarProps {
  onOpenMobile?: () => void;
  pageTitle?: string;
}

export function TopBar({ onOpenMobile, pageTitle }: TopBarProps) {
  return (
    <header className="sticky top-0 z-30 flex h-16 w-full items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
      {/* Left: Mobile Toggle & Page Title / Breadcrumb */}
      <div className="flex items-center gap-3">
        <button
          onClick={onOpenMobile}
          className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 md:hidden"
        >
          <Menu className="h-5 w-5" />
        </button>

        {pageTitle && (
          <h1 className="text-sm font-semibold text-slate-800 hidden sm:block">
            {pageTitle}
          </h1>
        )}

        {/* Global Search Input */}
        <div className="relative w-48 sm:w-64 lg:w-80">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-400" />
          <input
            type="text"
            placeholder="Search anything..."
            className="w-full rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-9 pr-3 text-xs text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:border-blue-600 focus:ring-1 focus:ring-blue-600"
          />
        </div>
      </div>

      {/* Right Actions */}
      <div className="flex items-center gap-3">
        {/* Workspace Quick Switcher */}
        <div className="hidden lg:flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs text-slate-700">
          <span className="h-2 w-2 rounded-full bg-emerald-500" />
          <span className="font-medium">Active Workspace</span>
          <ChevronDown className="h-3.5 w-3.5 text-slate-400" />
        </div>

        {/* Create Post Button */}
        <Link
          href="/composer"
          className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 active:scale-95 transition-all"
        >
          <Plus className="h-3.5 w-3.5" />
          <span className="hidden sm:inline">Create Post</span>
        </Link>

        {/* Notifications Icon with unread pill */}
        <Link
          href="/notifications"
          className="relative p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors"
          title="Notifications"
        >
          <Bell className="h-4 w-4" />
          <span className="absolute top-1 right-1 h-2 w-2 rounded-full bg-blue-600 ring-2 ring-white" />
        </Link>

        {/* Help / Docs */}
        <Link
          href="/settings/workspace"
          className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors hidden sm:block"
          title="Documentation"
        >
          <HelpCircle className="h-4 w-4" />
        </Link>

        {/* User Account Avatar */}
        <Link href="/settings/profile" className="flex items-center gap-2 pl-1">
          <img
            src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150"
            alt="John Doe"
            className="h-8 w-8 rounded-full object-cover border border-slate-200"
          />
        </Link>
      </div>
    </header>
  );
}
