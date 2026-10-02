'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import {
  Sliders,
  ArrowLeft,
  Save,
  Info,
  CheckCircle2,
  AlertTriangle,
  Play,
  RotateCcw,
  Check
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function PlatformCustomizationPage() {
  const params = useParams();
  const router = useRouter();
  const rawPlatform = (params?.platform as string) || 'youtube';
  const platform = rawPlatform.toLowerCase();

  const [saved, setSaved] = useState(false);

  // YouTube States
  const [ytTitle, setYtTitle] = useState('How We Scaled OmniPublish from 0 to 10k Users');
  const [ytDescription, setYtDescription] = useState(
    'A full technical breakdown of multi-platform video publishing, worker queues, and resilient social APIs.'
  );
  const [ytTags, setYtTags] = useState('saas, coding, architecture, devops');
  const [ytVisibility, setYtVisibility] = useState('public');
  const [ytCategory, setYtCategory] = useState('28'); // Science & Tech
  const [ytMadeForKids, setYtMadeForKids] = useState(false);

  // TikTok States
  const [ttCaption, setTtCaption] = useState(
    'Scaling a video publishing SaaS to 10k creators! #omnipublish #saas #coding #startup'
  );
  const [ttPrivacy, setTtPrivacy] = useState('PUBLIC_TO_EVERYONE');
  const [ttAllowComments, setTtAllowComments] = useState(true);
  const [ttAllowDuet, setTtAllowDuet] = useState(true);
  const [ttAllowStitch, setTtAllowStitch] = useState(true);

  // Instagram States
  const [igCaption, setIgCaption] = useState(
    'Behind the scenes building OmniPublish! Multi-platform publishing made simple. #dev #saas'
  );
  const [igShareToFeed, setIgShareToFeed] = useState(true);

  const handleSave = () => {
    setSaved(true);
    setTimeout(() => {
      router.push('/composer');
    }, 800);
  };

  const platforms = [
    { id: 'youtube', name: 'YouTube' },
    { id: 'tiktok', name: 'TikTok' },
    { id: 'instagram', name: 'Instagram' },
    { id: 'facebook', name: 'Facebook' },
    { id: 'linkedin', name: 'LinkedIn' },
    { id: 'x', name: 'X' },
  ];

  return (
    <AppShell pageTitle={`Platform Customization — ${platform.toUpperCase()}`}>
      <div className="max-w-5xl mx-auto space-y-6">
        {/* Header matching Screenshot 8 */}
        <div className="flex items-center justify-between border-b border-slate-200 pb-4">
          <div className="flex items-center gap-3">
            <Link
              href="/composer"
              className="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-600"
            >
              <ArrowLeft className="h-4 w-4" />
            </Link>
            <div>
              <h1 className="text-xl font-bold text-slate-900">Platform Customization</h1>
              <p className="text-xs text-slate-500">
                Fine-tune metadata strictly according to verified platform API capabilities.
              </p>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <Link
              href="/composer"
              className="rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </Link>
            <button
              onClick={handleSave}
              className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors"
            >
              {saved ? (
                <>
                  <Check className="h-3.5 w-3.5" />
                  <span>Saved!</span>
                </>
              ) : (
                <>
                  <Save className="h-3.5 w-3.5" />
                  <span>Save Overrides</span>
                </>
              )}
            </button>
          </div>
        </div>

        {/* Platform Tabs */}
        <div className="flex border-b border-slate-200 bg-white rounded-t-xl px-4 gap-2">
          {platforms.map((p) => {
            const isActive = platform === p.id;
            return (
              <Link
                key={p.id}
                href={`/composer/platform/${p.id}`}
                className={`flex items-center gap-2 border-b-2 py-3 px-3 text-xs font-semibold transition-colors ${
                  isActive
                    ? 'border-blue-600 text-blue-600'
                    : 'border-transparent text-slate-500 hover:text-slate-800'
                }`}
              >
                <span>{p.name}</span>
              </Link>
            );
          })}
        </div>

        {/* Content Body & Preview Split */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          {/* Controls Form (8 cols) */}
          <div className="lg:col-span-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
            {platform === 'youtube' && (
              <div className="space-y-4">
                <div className="flex items-center gap-2 rounded-lg bg-blue-50/70 p-3 text-xs text-blue-800 border border-blue-100">
                  <Info className="h-4 w-4 text-blue-600 shrink-0" />
                  <span>
                    Verified YouTube Data API v3 parameters: Max title 100 chars, description 5,000 chars, COPPA check.
                  </span>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    YouTube Title
                  </label>
                  <input
                    type="text"
                    value={ytTitle}
                    onChange={(e) => setYtTitle(e.target.value)}
                    maxLength={100}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    YouTube Description
                  </label>
                  <textarea
                    rows={4}
                    value={ytDescription}
                    onChange={(e) => setYtDescription(e.target.value)}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Tags (Comma separated)
                  </label>
                  <input
                    type="text"
                    value={ytTags}
                    onChange={(e) => setYtTags(e.target.value)}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                  />
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Visibility
                    </label>
                    <select
                      value={ytVisibility}
                      onChange={(e) => setYtVisibility(e.target.value)}
                      className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                    >
                      <option value="public">Public</option>
                      <option value="unlisted">Unlisted</option>
                      <option value="private">Private</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Category
                    </label>
                    <select
                      value={ytCategory}
                      onChange={(e) => setYtCategory(e.target.value)}
                      className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                    >
                      <option value="28">Science &amp; Technology</option>
                      <option value="27">Education</option>
                      <option value="24">Entertainment</option>
                      <option value="22">People &amp; Blogs</option>
                    </select>
                  </div>
                </div>

                <div className="flex items-center gap-3 pt-2">
                  <input
                    type="checkbox"
                    id="kidsCheck"
                    checked={ytMadeForKids}
                    onChange={(e) => setYtMadeForKids(e.target.checked)}
                    className="h-4 w-4 rounded border-slate-300 text-blue-600"
                  />
                  <label htmlFor="kidsCheck" className="text-xs text-slate-700">
                    Made for Kids (COPPA compliance declaration)
                  </label>
                </div>
              </div>
            )}

            {platform === 'tiktok' && (
              <div className="space-y-4">
                <div className="flex items-center gap-2 rounded-lg bg-blue-50/70 p-3 text-xs text-blue-800 border border-blue-100">
                  <Info className="h-4 w-4 text-blue-600 shrink-0" />
                  <span>
                    Verified TikTok Content Posting API v2: Single combined caption field (max 2,200 chars).
                  </span>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Caption &amp; Hashtags
                  </label>
                  <textarea
                    rows={4}
                    value={ttCaption}
                    onChange={(e) => setTtCaption(e.target.value)}
                    maxLength={2200}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Privacy Level
                  </label>
                  <select
                    value={ttPrivacy}
                    onChange={(e) => setTtPrivacy(e.target.value)}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                  >
                    <option value="PUBLIC_TO_EVERYONE">Public to Everyone</option>
                    <option value="MUTUAL_FOLLOW_FRIENDS">Friends Only</option>
                    <option value="SELF_ONLY">Private (Self Only)</option>
                  </select>
                </div>

                <div className="grid grid-cols-3 gap-2 pt-2">
                  <label className="flex items-center gap-2 text-xs text-slate-700">
                    <input
                      type="checkbox"
                      checked={ttAllowComments}
                      onChange={(e) => setTtAllowComments(e.target.checked)}
                      className="h-4 w-4 rounded border-slate-300 text-blue-600"
                    />
                    <span>Allow Comments</span>
                  </label>

                  <label className="flex items-center gap-2 text-xs text-slate-700">
                    <input
                      type="checkbox"
                      checked={ttAllowDuet}
                      onChange={(e) => setTtAllowDuet(e.target.checked)}
                      className="h-4 w-4 rounded border-slate-300 text-blue-600"
                    />
                    <span>Allow Duet</span>
                  </label>

                  <label className="flex items-center gap-2 text-xs text-slate-700">
                    <input
                      type="checkbox"
                      checked={ttAllowStitch}
                      onChange={(e) => setTtAllowStitch(e.target.checked)}
                      className="h-4 w-4 rounded border-slate-300 text-blue-600"
                    />
                    <span>Allow Stitch</span>
                  </label>
                </div>
              </div>
            )}

            {platform === 'instagram' && (
              <div className="space-y-4">
                <div className="flex items-center gap-2 rounded-lg bg-blue-50/70 p-3 text-xs text-blue-800 border border-blue-100">
                  <Info className="h-4 w-4 text-blue-600 shrink-0" />
                  <span>
                    Verified Instagram Graph API: Instagram Reels direct video publishing container.
                  </span>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Reel Caption
                  </label>
                  <textarea
                    rows={4}
                    value={igCaption}
                    onChange={(e) => setIgCaption(e.target.value)}
                    maxLength={2200}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                  />
                </div>

                <div className="flex items-center gap-3 pt-2">
                  <input
                    type="checkbox"
                    id="igFeed"
                    checked={igShareToFeed}
                    onChange={(e) => setIgShareToFeed(e.target.checked)}
                    className="h-4 w-4 rounded border-slate-300 text-blue-600"
                  />
                  <label htmlFor="igFeed" className="text-xs text-slate-700">
                    Also Share to Instagram Feed
                  </label>
                </div>
              </div>
            )}

            {['facebook', 'linkedin', 'x'].includes(platform) && (
              <div className="space-y-4">
                <div className="flex items-center gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 border border-amber-200">
                  <AlertTriangle className="h-4 w-4 text-amber-600 shrink-0" />
                  <span>
                    Enterprise connector: Video publishing uses standard multi-part upload protocol. Custom platform targeting not supported by this platform API.
                  </span>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Post Text / Commentary
                  </label>
                  <textarea
                    rows={4}
                    defaultValue="How We Scaled OmniPublish from 0 to 10k Users. Watch the full breakdown!"
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                  />
                </div>
              </div>
            )}
          </div>

          {/* Right Column: Platform Preview (4 cols) */}
          <div className="lg:col-span-4 space-y-4">
            <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-3">
              <h4 className="text-xs font-semibold text-slate-800 uppercase tracking-wider">
                {platform.toUpperCase()} Live Preview
              </h4>

              <div className="relative aspect-video rounded-lg overflow-hidden bg-slate-900 shadow-inner">
                <img
                  src="https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=500"
                  alt="Preview"
                  className="w-full h-full object-cover opacity-85"
                />
                <div className="absolute inset-0 bg-black/20 flex items-center justify-center">
                  <Play className="h-8 w-8 text-white fill-white" />
                </div>
              </div>

              <div className="space-y-1.5 text-xs text-slate-700">
                <p className="font-semibold text-slate-900 line-clamp-1">
                  {platform === 'youtube' ? ytTitle : platform === 'tiktok' ? ttCaption : igCaption}
                </p>
                <p className="text-[11px] text-slate-500">
                  Channel: <span className="font-medium text-slate-700">OmniPublish Studio</span>
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
