'use client';

import React, { useState, useEffect } from 'react';
import Link from 'next/link';
import {
  Film,
  Search,
  Filter,
  Grid,
  List,
  Plus,
  UploadCloud,
  MoreVertical,
  CheckCircle2,
  Clock,
  Play,
  Share2,
  Calendar,
  Trash2,
  Copy,
  ExternalLink,
  Archive
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';
import { PublishBatch } from '@/lib/types';

interface ContentItem {
  id: string;
  title: string;
  thumbnail: string;
  duration: string;
  status: 'Published' | 'Scheduled' | 'Draft';
  platforms: ('YOUTUBE' | 'TIKTOK' | 'INSTAGRAM' | 'FACEBOOK')[];
  createdAt: string;
  owner: { name: string; avatar: string };
  views?: string;
}

export default function ContentLibraryPage() {
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedPlatform, setSelectedPlatform] = useState('ALL');
  const [selectedStatus, setSelectedStatus] = useState('ALL');

  const [items, setItems] = useState<ContentItem[]>([]);
  const [loading, setLoading] = useState(true);

  const loadContent = async () => {
    try {
      setLoading(true);
      const batches = await api.getPublishBatches();
      if (Array.isArray(batches) && batches.length > 0) {
        const mapped: ContentItem[] = batches.map((batch: PublishBatch) => {
          const jobs = batch.publish_jobs || batch.jobs || [];
          const platforms = jobs.map((j) => j.platform as any);
          const allPublished = jobs.length > 0 && jobs.every((j) => j.status === 'PUBLISHED');
          const isScheduled = Boolean(batch.scheduled_for && new Date(batch.scheduled_for) > new Date());

          return {
            id: batch.id,
            title: batch.content_item?.title || batch.content_item?.global_metadata?.title || 'OmniPublish Video Post',
            thumbnail: batch.content_item?.media_asset?.thumbnail_path || batch.content_item?.media_asset?.url || 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600',
            duration: '01:45',
            status: isScheduled ? 'Scheduled' : allPublished ? 'Published' : 'Draft',
            platforms: platforms.length > 0 ? platforms : ['YOUTUBE', 'TIKTOK'],
            createdAt: batch.created_at ? new Date(batch.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Recently',
            owner: { name: 'Alex Vance', avatar: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=150' },
            views: '42.5K',
          };
        });
        setItems(mapped);
      }
    } catch (err) {
      console.error('Failed to load content items:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadContent();
  }, []);

  const handleDeleteItem = async (id: string) => {
    if (confirm('Are you sure you want to delete this content item?')) {
      try {
        await api.deletePublishBatch(id);
        setItems((prev) => prev.filter((i) => i.id !== id));
      } catch (err) {
        console.error('Failed to delete item:', err);
      }
    }
  };

  const filteredItems = items.filter((item) => {
    const matchesSearch = item.title.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesPlatform = selectedPlatform === 'ALL' || item.platforms.includes(selectedPlatform as any);
    const matchesStatus = selectedStatus === 'ALL' || item.status === selectedStatus;
    return matchesSearch && matchesPlatform && matchesStatus;
  });

  return (
    <AppShell pageTitle="Content Library">
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Content Library</h1>
            <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
              Manage your master video assets, drafts, and multi-channel publication records.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <Link
              href="/upload"
              className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
            >
              <UploadCloud className="h-4 w-4" />
              <span>Upload Video</span>
            </Link>
            <Link
              href="/composer"
              className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
            >
              <Plus className="h-4 w-4" />
              <span>Create Post</span>
            </Link>
          </div>
        </div>

        {/* Toolbar: Search, Filters, Grid/List (Screenshot 11) */}
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
          <div className="flex flex-1 items-center gap-3">
            {/* Search */}
            <div className="relative flex-1 max-w-sm">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-400" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search videos and content..."
                className="w-full rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-9 pr-3 text-xs text-slate-900 focus:bg-white focus:border-blue-600 focus:outline-none"
              />
            </div>

            {/* Platform filter */}
            <select
              value={selectedPlatform}
              onChange={(e) => setSelectedPlatform(e.target.value)}
              className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-700 focus:border-blue-600 focus:outline-none"
            >
              <option value="ALL">All Platforms</option>
              <option value="YOUTUBE">YouTube</option>
              <option value="TIKTOK">TikTok</option>
              <option value="INSTAGRAM">Instagram</option>
              <option value="FACEBOOK">Facebook</option>
            </select>

            {/* Status filter */}
            <select
              value={selectedStatus}
              onChange={(e) => setSelectedStatus(e.target.value)}
              className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-700 focus:border-blue-600 focus:outline-none"
            >
              <option value="ALL">All Statuses</option>
              <option value="Published">Published</option>
              <option value="Scheduled">Scheduled</option>
              <option value="Draft">Draft</option>
            </select>
          </div>

          {/* Grid / List View Toggle */}
          <div className="flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1">
            <button
              onClick={() => setViewMode('grid')}
              className={`p-1.5 rounded ${
                viewMode === 'grid' ? 'bg-white shadow-xs text-blue-600' : 'text-slate-400 hover:text-slate-700'
              }`}
            >
              <Grid className="h-4 w-4" />
            </button>
            <button
              onClick={() => setViewMode('list')}
              className={`p-1.5 rounded ${
                viewMode === 'list' ? 'bg-white shadow-xs text-blue-600' : 'text-slate-400 hover:text-slate-700'
              }`}
            >
              <List className="h-4 w-4" />
            </button>
          </div>
        </div>

        {/* Content View: Grid or List */}
        {viewMode === 'grid' ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            {filteredItems.map((item) => (
              <div
                key={item.id}
                className="group rounded-xl border border-slate-200 bg-white overflow-hidden shadow-sm hover:shadow-md transition-all flex flex-col"
              >
                {/* Thumbnail */}
                <Link href={`/content/${item.id}`} className="relative aspect-video bg-slate-900 overflow-hidden block">
                  <img
                    src={item.thumbnail}
                    alt={item.title}
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300 opacity-90"
                  />
                  <div className="absolute inset-0 bg-black/20 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                    <div className="h-9 w-9 rounded-full bg-white/90 text-slate-900 flex items-center justify-center shadow">
                      <Play className="h-4 w-4 fill-slate-900 ml-0.5" />
                    </div>
                  </div>
                  <span className="absolute bottom-2 right-2 rounded bg-black/75 px-1.5 py-0.5 text-[10px] font-mono text-white">
                    {item.duration}
                  </span>
                </Link>

                {/* Body */}
                <div className="p-4 flex-1 flex flex-col justify-between space-y-3">
                  <div>
                    <div className="flex items-center justify-between mb-1.5">
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                          item.status === 'Published'
                            ? 'bg-emerald-50 text-emerald-700'
                            : item.status === 'Scheduled'
                            ? 'bg-purple-50 text-purple-700'
                            : 'bg-slate-100 text-slate-700'
                        }`}
                      >
                        {item.status}
                      </span>

                      <div className="flex items-center gap-1">
                        {item.platforms.map((p) => (
                          <span
                            key={p}
                            className={`h-2 w-2 rounded-full ${
                              p === 'YOUTUBE'
                                ? 'bg-red-500'
                                : p === 'TIKTOK'
                                ? 'bg-black'
                                : p === 'INSTAGRAM'
                                ? 'bg-pink-500'
                                : 'bg-blue-600'
                            }`}
                          />
                        ))}
                      </div>
                    </div>

                    <Link href={`/content/${item.id}`}>
                      <h3 className="text-xs font-bold text-slate-900 line-clamp-2 hover:text-blue-600">
                        {item.title}
                      </h3>
                    </Link>
                  </div>

                  <div className="flex items-center justify-between pt-2 border-t border-slate-100 text-[11px] text-slate-400">
                    <div className="flex items-center gap-1.5">
                      <img src={item.owner.avatar} alt="Owner" className="h-4 w-4 rounded-full" />
                      <span>{item.createdAt}</span>
                    </div>

                    <div className="flex items-center gap-1">
                      <Link
                        href={`/content/${item.id}`}
                        className="text-xs text-blue-600 hover:underline font-semibold"
                      >
                        Open →
                      </Link>
                      <button
                        type="button"
                        onClick={() => handleDeleteItem(item.id)}
                        className="text-slate-400 hover:text-rose-600 transition-colors p-1"
                        title="Delete Post"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        ) : (
          /* List View Table */
          <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                <tr>
                  <th className="px-4 py-3">Content Item</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Platforms</th>
                  <th className="px-4 py-3">Duration</th>
                  <th className="px-4 py-3">Created</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredItems.map((item) => (
                  <tr key={item.id} className="hover:bg-slate-50/70">
                    <td className="px-4 py-3">
                      <Link href={`/content/${item.id}`} className="flex items-center gap-3">
                        <img
                          src={item.thumbnail}
                          alt="thumb"
                          className="h-10 w-16 object-cover rounded bg-slate-900 shrink-0"
                        />
                        <span className="font-semibold text-slate-900 hover:text-blue-600 max-w-sm truncate">
                          {item.title}
                        </span>
                      </Link>
                    </td>
                    <td className="px-4 py-3">
                      <span
                        className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                          item.status === 'Published'
                            ? 'bg-emerald-50 text-emerald-700'
                            : item.status === 'Scheduled'
                            ? 'bg-purple-50 text-purple-700'
                            : 'bg-slate-100 text-slate-700'
                        }`}
                      >
                        {item.status}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1">
                        {item.platforms.map((p) => (
                          <span
                            key={p}
                            className={`rounded px-1.5 py-0.5 text-[10px] font-bold text-white ${
                              p === 'YOUTUBE'
                                ? 'bg-red-600'
                                : p === 'TIKTOK'
                                ? 'bg-black'
                                : p === 'INSTAGRAM'
                                ? 'bg-pink-600'
                                : 'bg-blue-600'
                            }`}
                          >
                            {p.slice(0, 2)}
                          </span>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-3 font-mono text-slate-500">{item.duration}</td>
                    <td className="px-4 py-3 text-slate-500">{item.createdAt}</td>
                    <td className="px-4 py-3 text-right">
                      <Link
                        href={`/content/${item.id}`}
                        className="rounded border border-slate-200 px-2.5 py-1 text-slate-700 hover:bg-slate-100 font-medium"
                      >
                        View Details
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </AppShell>
  );
}
