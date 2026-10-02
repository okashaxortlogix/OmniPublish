'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  LayoutDashboard,
  PenTool,
  Film,
  Share2,
  Activity,
  Calendar,
  BarChart3,
  Sparkles,
  Layers,
  ChevronDown
} from 'lucide-react';

const NAV_ITEMS = [
  { name: 'Dashboard', href: '/', icon: LayoutDashboard },
  { name: 'Composer', href: '/composer', icon: PenTool },
  { name: 'Channels', href: '/channels', icon: Share2 },
  { name: 'Media Library', href: '/library', icon: Film },
  { name: 'Publish Monitor', href: '/monitor', icon: Activity },
  { name: 'Calendar', href: '/calendar', icon: Calendar },
  { name: 'Analytics', href: '/analytics', icon: BarChart3 },
];

export function Navbar() {
  const pathname = usePathname();

  return (
    <header className="sticky top-0 z-50 w-full border-b border-white/[0.08] bg-[#07090e]/85 backdrop-blur-xl">
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        {/* Brand Logo & Workspace Switcher */}
        <div className="flex items-center gap-6">
          <Link href="/" className="flex items-center gap-2.5 transition-transform hover:scale-[1.02]">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-brand-indigo via-brand-violet to-brand-cyan shadow-glow-indigo">
              <Layers className="h-5 w-5 text-white" />
            </div>
            <div>
              <span className="font-heading text-lg font-bold tracking-tight text-white">
                Omni<span className="gradient-brand-text">Publish</span>
              </span>
              <span className="ml-1.5 rounded bg-brand-indigo/15 px-1.5 py-0.5 text-[10px] font-semibold text-brand-cyan border border-brand-indigo/30">
                STUDIO PRO
              </span>
            </div>
          </Link>

          {/* Workspace Pill */}
          <div className="hidden sm:flex items-center gap-2 rounded-lg border border-white/[0.08] bg-canvas-surface/80 px-2.5 py-1 text-xs text-slate-300">
            <span className="h-2 w-2 rounded-full bg-emerald-400 animate-pulse" />
            <span className="font-medium">OmniPublish Studio</span>
            <ChevronDown className="h-3.5 w-3.5 text-slate-400" />
          </div>
        </div>

        {/* Navigation Links */}
        <nav className="hidden md:flex items-center gap-1">
          {NAV_ITEMS.map((item) => {
            const Icon = item.icon;
            const isActive = pathname === item.href;
            return (
              <Link
                key={item.name}
                href={item.href}
                className={`flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium transition-all ${
                  isActive
                    ? 'bg-brand-indigo/20 text-brand-cyan border border-brand-indigo/40 shadow-sm'
                    : 'text-slate-400 hover:bg-white/[0.05] hover:text-slate-200'
                }`}
              >
                <Icon className={`h-4 w-4 ${isActive ? 'text-brand-cyan' : 'text-slate-400'}`} />
                {item.name}
              </Link>
            );
          })}
        </nav>

        {/* Right CTA */}
        <div className="flex items-center gap-3">
          <Link
            href="/composer"
            className="flex items-center gap-2 rounded-lg bg-gradient-to-r from-brand-indigo to-brand-violet px-3.5 py-1.5 text-xs font-semibold text-white shadow-glow-indigo transition-all hover:scale-[1.02] hover:brightness-110 active:scale-[0.98]"
          >
            <Sparkles className="h-3.5 w-3.5" />
            <span>Create Post</span>
          </Link>

          {/* User Profile Avatar */}
          <div className="flex h-8 w-8 items-center justify-center rounded-full border border-white/20 bg-gradient-to-tr from-purple-500 to-indigo-600 text-xs font-bold text-white shadow-sm">
            AV
          </div>
        </div>
      </div>
    </header>
  );
}
