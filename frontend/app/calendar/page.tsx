'use client';

import React, { useState, useEffect } from 'react';
import Link from 'next/link';
import {
  Calendar as CalendarIcon,
  ChevronLeft,
  ChevronRight,
  Plus,
  Clock,
  Filter,
  Layers,
  X,
  CheckCircle2,
  AlertCircle,
  Play
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';
import { PublishBatch } from '@/lib/types';

interface ScheduledItem {
  id: string;
  day: number;
  time: string;
  title: string;
  platforms: ('YOUTUBE' | 'TIKTOK' | 'INSTAGRAM' | 'FACEBOOK')[];
  status: 'Scheduled' | 'Published' | 'Draft' | 'Failed';
  color: string;
}

export default function CalendarPage() {
  const [viewMode, setViewMode] = useState<'Month' | 'Week' | 'Day' | 'List'>('Month');
  const [currentMonth, setCurrentMonth] = useState('October 2026');
  const [selectedPlatforms, setSelectedPlatforms] = useState<string[]>([
    'YouTube',
    'TikTok',
    'Instagram',
    'Facebook',
    'LinkedIn',
    'X',
  ]);
  const [selectedStatuses, setSelectedStatuses] = useState<string[]>([
    'Scheduled',
    'Published',
    'Draft',
    'Failed',
  ]);

  const [scheduleModalOpen, setScheduleModalOpen] = useState(false);
  const [newTitle, setNewTitle] = useState('');
  const [newDate, setNewDate] = useState('2026-10-15');
  const [newTime, setNewTime] = useState('16:00');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [calendarItems, setCalendarItems] = useState<ScheduledItem[]>([]);
  const [loading, setLoading] = useState(true);

  const loadCalendarEvents = async () => {
    try {
      setLoading(true);
      const batches = await api.getCalendar();
      if (Array.isArray(batches) && batches.length > 0) {
        const mapped: ScheduledItem[] = batches.map((batch: PublishBatch) => {
          const dateObj = new Date(batch.scheduled_for || batch.created_at || Date.now());
          const day = dateObj.getDate() || 1;
          const time = dateObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
          const title = batch.content_item?.title || batch.content_item?.global_metadata?.title || 'OmniPublish Video Post';

          const jobs = batch.publish_jobs || batch.jobs || [];
          const platforms = jobs.map((j) => j.platform as any);
          const hasFailed = jobs.some((j) => j.status === 'FAILED');
          const allCompleted = jobs.length > 0 && jobs.every((j) => j.status === 'PUBLISHED');

          let status: ScheduledItem['status'] = 'Draft';
          let color = 'border-emerald-500 bg-emerald-50/70 text-emerald-900';

          if (batch.scheduled_for && new Date(batch.scheduled_for) > new Date()) {
            status = 'Scheduled';
            color = 'border-purple-500 bg-purple-50/70 text-purple-900';
          } else if (allCompleted) {
            status = 'Published';
            color = 'border-blue-500 bg-blue-50/70 text-blue-900';
          } else if (hasFailed) {
            status = 'Failed';
            color = 'border-rose-500 bg-rose-50/70 text-rose-900';
          }

          return {
            id: batch.id,
            day,
            time,
            title,
            platforms: platforms.length > 0 ? platforms : ['YOUTUBE'],
            status,
            color,
          };
        });
        setCalendarItems(mapped);
      }
    } catch (err) {
      console.error('Failed to load calendar events:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadCalendarEvents();
  }, []);

  const togglePlatform = (p: string) => {
    setSelectedPlatforms((prev) =>
      prev.includes(p) ? prev.filter((x) => x !== p) : [...prev, p]
    );
  };

  const toggleStatus = (s: string) => {
    setSelectedStatuses((prev) =>
      prev.includes(s) ? prev.filter((x) => x !== s) : [...prev, s]
    );
  };

  const handleAddSchedule = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTitle.trim()) return;

    setIsSubmitting(true);
    try {
      await api.createPublishBatch({
        globalMetadata: {
          title: newTitle,
          description: `Scheduled dispatch from OmniPublish Studio calendar.`,
          caption: newTitle,
          visibility: 'PUBLIC',
        },
        platformOverrides: {},
        targetPlatforms: ['YOUTUBE', 'TIKTOK'],
        scheduledFor: `${newDate}T${newTime}:00`,
      });

      await loadCalendarEvents();
      setScheduleModalOpen(false);
      setNewTitle('');
    } catch (err) {
      console.error('Failed to schedule batch:', err);
    } finally {
      setIsSubmitting(false);
    }
  };

  // Calendar Days for April 2026 (starts Wednesday = 3 padding days)
  const paddingDays = [29, 30, 31];
  const daysInApril = Array.from({ length: 30 }, (_, i) => i + 1);

  return (
    <AppShell pageTitle="Content Calendar">
      <div className="space-y-6">
        {/* Top Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Content Calendar</h1>
            <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
              Plan, coordinate, and automatically publish video schedules across all channels.
            </p>
          </div>

          <div className="flex items-center gap-3">
            {/* View Switcher: Month, Week, Day, List */}
            <div className="flex rounded-lg border border-slate-200 bg-white p-1 text-xs shadow-sm">
              {(['Month', 'Week', 'Day', 'List'] as const).map((mode) => (
                <button
                  key={mode}
                  onClick={() => setViewMode(mode)}
                  className={`rounded-md px-3 py-1 font-medium transition-colors ${
                    viewMode === mode
                      ? 'bg-blue-600 text-white font-semibold'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  {mode}
                </button>
              ))}
            </div>

            <button
              onClick={() => setScheduleModalOpen(true)}
              className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors"
            >
              <Plus className="h-3.5 w-3.5" />
              <span>Schedule Post</span>
            </button>
          </div>
        </div>

        {/* Calendar Body + Filter Sidebar Grid (Screenshot 10) */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          {/* Calendar Grid Container (9 cols) */}
          <div className="lg:col-span-9 rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            {/* Month Navigation Bar */}
            <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  className="rounded-lg border border-slate-200 p-1.5 text-slate-500 hover:bg-slate-50 hover:text-slate-900"
                >
                  <ChevronLeft className="h-4 w-4" />
                </button>
                <h2 className="text-base font-bold text-slate-900 min-w-[120px] text-center">
                  {currentMonth}
                </h2>
                <button
                  type="button"
                  className="rounded-lg border border-slate-200 p-1.5 text-slate-500 hover:bg-slate-50 hover:text-slate-900"
                >
                  <ChevronRight className="h-4 w-4" />
                </button>
              </div>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                >
                  Today
                </button>
              </div>
            </div>

            {/* Day of Week Headers */}
            <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs font-semibold text-slate-600 py-2.5">
              <span>Sun</span>
              <span>Mon</span>
              <span>Tue</span>
              <span>Wed</span>
              <span>Thu</span>
              <span>Fri</span>
              <span>Sat</span>
            </div>

            {/* Calendar Days Cells */}
            <div className="grid grid-cols-7 auto-rows-fr divide-x divide-y divide-slate-100 min-h-[560px]">
              {/* Previous Month Padding */}
              {paddingDays.map((d) => (
                <div key={`pad-${d}`} className="bg-slate-50/50 p-2 text-xs text-slate-300">
                  <span className="font-medium">{d}</span>
                </div>
              ))}

              {/* Current Month Days */}
              {daysInApril.map((day) => {
                const itemsOnThisDay = calendarItems.filter((i) => i.day === day);
                const isToday = day === 2;

                return (
                  <div
                    key={day}
                    className={`p-2 min-h-[95px] transition-colors hover:bg-blue-50/20 group relative ${
                      isToday ? 'bg-blue-50/40' : 'bg-white'
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <span
                        className={`text-xs font-semibold rounded-full h-6 w-6 flex items-center justify-center ${
                          isToday ? 'bg-blue-600 text-white font-bold' : 'text-slate-700'
                        }`}
                      >
                        {day}
                      </span>
                    </div>

                    {/* Scheduled Item Pills */}
                    <div className="mt-1.5 space-y-1">
                      {itemsOnThisDay.map((item) => (
                        <div
                          key={item.id}
                          className={`rounded border p-1.5 text-[11px] shadow-sm leading-tight cursor-pointer hover:shadow transition-shadow ${item.color}`}
                        >
                          <div className="flex items-center justify-between">
                            <span className="font-semibold truncate">{item.title}</span>
                            <span className="text-[9px] font-mono opacity-80">{item.time}</span>
                          </div>
                          <div className="flex items-center gap-1 mt-1">
                            {item.platforms.map((p) => (
                              <span
                                key={p}
                                className={`h-1.5 w-1.5 rounded-full ${
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
                            <span className="text-[9px] opacity-75 capitalize">{item.status}</span>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Right Filter Sidebar (Screenshot 10) (3 cols) */}
          <div className="lg:col-span-3 space-y-4">
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-5">
              <div className="flex items-center gap-2 border-b border-slate-100 pb-3">
                <Filter className="h-4 w-4 text-slate-500" />
                <h3 className="text-sm font-semibold text-slate-900">Filters</h3>
              </div>

              {/* Platform Filters */}
              <div className="space-y-2">
                <label className="text-xs font-semibold text-slate-500 uppercase tracking-wider block">
                  Platforms
                </label>
                {['YouTube', 'TikTok', 'Instagram', 'Facebook', 'LinkedIn', 'X'].map((plat) => {
                  const checked = selectedPlatforms.includes(plat);
                  return (
                    <label
                      key={plat}
                      className="flex items-center justify-between text-xs text-slate-700 cursor-pointer hover:text-slate-900"
                    >
                      <div className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => togglePlatform(plat)}
                          className="h-3.5 w-3.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span>{plat}</span>
                      </div>
                      <span
                        className={`h-2 w-2 rounded-full ${
                          plat === 'YouTube'
                            ? 'bg-red-500'
                            : plat === 'TikTok'
                            ? 'bg-black'
                            : plat === 'Instagram'
                            ? 'bg-pink-500'
                            : plat === 'Facebook'
                            ? 'bg-blue-600'
                            : 'bg-slate-400'
                        }`}
                      />
                    </label>
                  );
                })}
              </div>

              {/* Status Filters */}
              <div className="space-y-2 pt-2 border-t border-slate-100">
                <label className="text-xs font-semibold text-slate-500 uppercase tracking-wider block">
                  Status
                </label>
                {['Scheduled', 'Published', 'Draft', 'Failed'].map((st) => {
                  const checked = selectedStatuses.includes(st);
                  return (
                    <label
                      key={st}
                      className="flex items-center justify-between text-xs text-slate-700 cursor-pointer hover:text-slate-900"
                    >
                      <div className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => toggleStatus(st)}
                          className="h-3.5 w-3.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span>{st}</span>
                      </div>
                      <span
                        className={`h-2 w-2 rounded-full ${
                          st === 'Published'
                            ? 'bg-emerald-500'
                            : st === 'Scheduled'
                            ? 'bg-purple-500'
                            : st === 'Draft'
                            ? 'bg-slate-400'
                            : 'bg-red-500'
                        }`}
                      />
                    </label>
                  );
                })}
              </div>
            </div>
          </div>
        </div>

        {/* Modal: Schedule Post */}
        {scheduleModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
            <div className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-xl space-y-4">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 className="text-sm font-bold text-slate-900">Schedule New Post</h3>
                <button
                  onClick={() => setScheduleModalOpen(false)}
                  className="text-slate-400 hover:text-slate-700"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>

              <form onSubmit={handleAddSchedule} className="space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Post Title
                  </label>
                  <input
                    type="text"
                    value={newTitle}
                    onChange={(e) => setNewTitle(e.target.value)}
                    required
                    placeholder="e.g. Weekly Product Update"
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">Date</label>
                    <input
                      type="date"
                      value={newDate}
                      onChange={(e) => setNewDate(e.target.value)}
                      className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">Time</label>
                    <input
                      type="time"
                      value={newTime}
                      onChange={(e) => setNewTime(e.target.value)}
                      className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
                    />
                  </div>
                </div>

                <div className="flex justify-end gap-2 pt-2 border-t border-slate-100">
                  <button
                    type="button"
                    onClick={() => setScheduleModalOpen(false)}
                    className="rounded-lg border border-slate-200 px-3.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
                  >
                    Add to Calendar
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
