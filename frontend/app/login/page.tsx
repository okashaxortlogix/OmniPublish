'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  Layers,
  ArrowRight,
  ShieldCheck,
  CheckCircle2,
  AlertCircle
} from 'lucide-react';
import { api, setStoredAuthToken, setStoredWorkspaceId } from '@/lib/api';

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [rememberMe, setRememberMe] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    const apiBase = process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8001/api/v1';

    try {
      const res = await fetch(`${apiBase}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ email, password }),
      });

      const data = await res.json();
      if (!res.ok || data.success === false) {
        throw new Error(data.message || 'Invalid email or password');
      }

      if (data.data?.token) {
        setStoredAuthToken(data.data.token);
      }
      if (data.data?.currentWorkspace) {
        setStoredWorkspaceId(data.data.currentWorkspace.slug || data.data.currentWorkspace.id);
      }
      if (data.data?.user) {
        localStorage.setItem('omnipublish_user', JSON.stringify(data.data.user));
      }

      router.push('/dashboard');
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Login failed. Please check your credentials.';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen bg-slate-50">
      {/* Left Column: Branding, Statement & Platform Badges */}
      <div className="hidden lg:flex lg:w-1/2 flex-col justify-between bg-gradient-to-br from-blue-50 via-indigo-50/40 to-slate-100 p-12 border-r border-slate-200">
        <div className="flex items-center gap-2.5">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm font-bold">
            <Layers className="h-5 w-5" />
          </div>
          <span className="font-bold text-xl text-slate-900 tracking-tight">
            Omni<span className="text-blue-600">Publish</span>
          </span>
        </div>

        <div className="max-w-md space-y-6">
          <div className="space-y-3">
            <h1 className="text-4xl font-extrabold tracking-tight text-slate-900 leading-tight">
              Upload Once. <br />
              <span className="text-blue-600">Publish Everywhere.</span>
            </h1>
            <p className="text-sm text-slate-600 leading-relaxed">
              Manage your social video content across multiple platforms — all in one centralized studio workspace.
            </p>
          </div>

          {/* Social Platform Badges */}
          <div className="grid grid-cols-2 gap-3 pt-2">
            {[
              { name: 'YouTube', color: 'text-red-600 bg-red-50 border-red-200' },
              { name: 'TikTok', color: 'text-slate-900 bg-slate-100 border-slate-300' },
              { name: 'Instagram', color: 'text-pink-600 bg-pink-50 border-pink-200' },
              { name: 'Facebook', color: 'text-blue-600 bg-blue-50 border-blue-200' },
              { name: 'LinkedIn', color: 'text-sky-700 bg-sky-50 border-sky-200' },
              { name: 'X (Twitter)', color: 'text-slate-800 bg-slate-100 border-slate-200' },
            ].map((p) => (
              <div
                key={p.name}
                className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-semibold ${p.color}`}
              >
                <span className="h-2 w-2 rounded-full bg-current" />
                <span>{p.name}</span>
              </div>
            ))}
          </div>

          {/* Publishing Illustration Box */}
          <div className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm space-y-3">
            <div className="flex items-center justify-between text-xs text-slate-500">
              <span className="font-medium text-slate-700">Multi-Channel Pipeline</span>
              <span className="text-emerald-600 font-semibold flex items-center gap-1">
                <CheckCircle2 className="h-3.5 w-3.5" /> 100% Synced
              </span>
            </div>
            <div className="flex items-center justify-between gap-2 pt-2">
              <div className="rounded-lg bg-slate-100 p-2 text-center flex-1">
                <p className="text-[10px] text-slate-500">1 Video</p>
                <p className="font-bold text-xs text-slate-800">4K Master</p>
              </div>
              <ArrowRight className="h-4 w-4 text-slate-400" />
              <div className="rounded-lg bg-blue-50 p-2 text-center flex-1 border border-blue-100">
                <p className="text-[10px] text-blue-600 font-medium">Auto-Tailored</p>
                <p className="font-bold text-xs text-blue-700">6 Platforms</p>
              </div>
            </div>
          </div>
        </div>

        <div className="text-xs text-slate-400">
          © 2026 OmniPublish Technologies Inc. All rights reserved.
        </div>
      </div>

      {/* Right Column: Sign In Form */}
      <div className="flex flex-1 flex-col justify-center px-6 py-12 sm:px-12 lg:px-16 bg-white">
        <div className="mx-auto w-full max-w-sm space-y-6">
          {/* Header */}
          <div className="space-y-1">
            <h2 className="text-2xl font-bold tracking-tight text-slate-900">Welcome Back</h2>
            <p className="text-xs text-slate-500">Sign in to your account to continue</p>
          </div>

          {error && (
            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-600">
              <AlertCircle className="h-4 w-4 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {/* Form */}
          <form onSubmit={handleLogin} className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">
                Email address
              </label>
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@example.com"
                className="w-full saas-input px-3 py-2 text-xs"
              />
            </div>

            <div>
              <div className="flex items-center justify-between mb-1">
                <label className="block text-xs font-medium text-slate-700">Password</label>
                <Link
                  href="/forgot-password"
                  className="text-[11px] font-medium text-blue-600 hover:text-blue-700 hover:underline"
                >
                  Forgot password?
                </Link>
              </div>
              <input
                type="password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                className="w-full saas-input px-3 py-2 text-xs"
              />
            </div>

            <div className="flex items-center">
              <input
                id="remember-me"
                type="checkbox"
                checked={rememberMe}
                onChange={(e) => setRememberMe(e.target.checked)}
                className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
              />
              <label htmlFor="remember-me" className="ml-2 block text-xs text-slate-600">
                Remember me
              </label>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full flex justify-center items-center py-2.5 px-4 rounded-lg bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-semibold text-xs shadow-sm transition-colors disabled:opacity-50"
            >
              {loading ? 'Signing in...' : 'Sign In'}
            </button>
          </form>

          {/* Footer Link */}
          <div className="text-center text-xs text-slate-500">
            Don&apos;t have an account?{' '}
            <Link href="/register" className="font-semibold text-blue-600 hover:text-blue-700 hover:underline">
              Create one
            </Link>
          </div>

          <div className="pt-4 border-t border-slate-100 text-center text-[11px] text-slate-400">
            By signing in you agree to our{' '}
            <a href="#" className="underline">Terms</a> and{' '}
            <a href="#" className="underline">Privacy Policy</a>.
          </div>
        </div>
      </div>
    </div>
  );
}
