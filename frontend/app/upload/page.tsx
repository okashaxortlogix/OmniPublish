'use client';

import React, { useState, useRef } from 'react';
import { useRouter } from 'next/navigation';
import {
  UploadCloud,
  Film,
  CheckCircle2,
  AlertCircle,
  Play,
  RotateCcw,
  Image as ImageIcon,
  ArrowRight,
  FileVideo,
  X,
  Loader2,
  RefreshCw,
  Sparkles
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';
import { MediaAsset } from '@/lib/types';

export default function UploadPage() {
  const router = useRouter();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [dragActive, setDragActive] = useState(false);
  const [file, setFile] = useState<{
    name: string;
    size: string;
    duration: string;
    resolution: string;
    format: string;
  } | null>(null);

  const [uploadProgress, setUploadProgress] = useState<number>(0);
  const [isUploading, setIsUploading] = useState<boolean>(false);
  const [uploadStatusText, setUploadStatusText] = useState<string>('Uploading asset...');
  const [uploadedAssetId, setUploadedAssetId] = useState<string | null>(null);
  const [validationError, setValidationError] = useState<string | null>(null);

  // Phase 2: Processing state machine tracking
  const [processingStatus, setProcessingStatus] = useState<'QUEUED' | 'PROCESSING' | 'READY' | 'FAILED' | null>(null);
  const [processingStage, setProcessingStage] = useState<string | null>(null);
  const [processingError, setProcessingError] = useState<string | null>(null);
  const [mediaDetails, setMediaDetails] = useState<MediaAsset | null>(null);

  // Form Fields
  const [assetName, setAssetName] = useState('Product_Launch_V2_Final.mp4');
  const [videoTitle, setVideoTitle] = useState('How We Scaled OmniPublish from 0 to 10k Users');
  const [description, setDescription] = useState(
    'A full breakdown of the architecture, multi-platform publishing pipelines, and lessons learned while building OmniPublish.'
  );
  const [tags, setTags] = useState(['SaaS', 'Startup', 'Engineering']);
  const [tagInput, setTagInput] = useState('');
  const [hashtags, setHashtags] = useState(['#omnipublish', '#saas', '#tech']);
  const [hashtagInput, setHashtagInput] = useState('');

  // Handle Drag & Drop
  const handleDrag = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true);
    } else if (e.type === 'dragleave') {
      setDragActive(false);
    }
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      handleSelectedFile(e.dataTransfer.files[0]);
    }
  };

  const handleFileInput = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      handleSelectedFile(e.target.files[0]);
    }
  };

  const handleSelectedFile = async (selectedFile: File) => {
    setValidationError(null);
    // Validate format
    const validExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
    const ext = selectedFile.name.split('.').pop()?.toLowerCase() || '';

    if (!validExtensions.includes(ext)) {
      setValidationError(`Unsupported file format (.${ext}). Please upload MP4, MOV, or AVI.`);
      return;
    }

    // Size limit: 500MB
    if (selectedFile.size > 500 * 1024 * 1024) {
      setValidationError('File exceeds the 500MB size limit.');
      return;
    }

    setIsUploading(true);
    setUploadProgress(5);
    setUploadStatusText('Requesting upload authorization...');

    try {
      // 1. Request upload authorization from backend API
      const auth = await api.requestUploadAuthorization(
        selectedFile.name,
        selectedFile.type || 'video/mp4',
        selectedFile.size
      );

      setUploadStatusText('Uploading to object storage...');
      setUploadProgress(20);

      // 2. Direct upload to storage endpoint (S3 presigned PUT or direct stream POST)
      await new Promise<void>((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(auth.method, auth.upload_url, true);

        // Apply headers
        if (auth.headers) {
          Object.entries(auth.headers).forEach(([k, v]) => {
            xhr.setRequestHeader(k, v);
          });
        }

        xhr.upload.onprogress = (e) => {
          if (e.lengthComputable) {
            const percent = Math.min(85, Math.round(20 + (e.loaded / e.total) * 65));
            setUploadProgress(percent);
          }
        };

        xhr.onload = () => {
          if (xhr.status >= 200 && xhr.status < 300) {
            resolve();
          } else {
            reject(new Error(`Storage upload failed with HTTP ${xhr.status}`));
          }
        };

        xhr.onerror = () => reject(new Error('Network error during storage upload.'));
        xhr.ontimeout = () => reject(new Error('Storage upload timed out.'));

        if (auth.method === 'POST') {
          const formData = new FormData();
          formData.append('file', selectedFile);
          xhr.send(formData);
        } else {
          xhr.send(selectedFile);
        }
      });

      // 3. Backend Verification & Async Queuing
      setUploadStatusText('Backend verifying uploaded object...');
      setUploadProgress(90);

      const confirmedMedia = await api.confirmMediaUpload(auth.media_id);

      setUploadProgress(100);
      setIsUploading(false);
      setUploadedAssetId(confirmedMedia.id);
      setProcessingStatus(confirmedMedia.status as any);
      setProcessingStage(confirmedMedia.processing_stage || 'queued');
      setAssetName(selectedFile.name);

      setFile({
        name: selectedFile.name,
        size: `${(selectedFile.size / (1024 * 1024)).toFixed(1)} MB`,
        duration: confirmedMedia.duration_seconds ? `${Math.floor(confirmedMedia.duration_seconds / 60)}:${Math.round(confirmedMedia.duration_seconds % 60).toString().padStart(2, '0')}` : 'Analyzing...',
        resolution: confirmedMedia.width && confirmedMedia.height ? `${confirmedMedia.width}x${confirmedMedia.height}` : 'Probing...',
        format: ext.toUpperCase(),
      });

      // Poll background processing worker
      pollProcessingStatus(confirmedMedia.id);
    } catch (err: unknown) {
      console.error('Direct upload pipeline error:', err);
      setIsUploading(false);
      setUploadProgress(0);
      const msg = err instanceof Error ? err.message : 'Upload failed. Please verify storage and network connectivity.';
      setValidationError(msg);
    }
  };

  const pollProcessingStatus = (assetId: string) => {
    let attempts = 0;
    const maxAttempts = 60; // 2 minutes (every 2s)
    const interval = setInterval(async () => {
      attempts++;
      try {
        const asset = await api.getMediaAsset(assetId);
        setProcessingStatus(asset.status as any);
        setProcessingStage(asset.processing_stage || null);
        setMediaDetails(asset);

        if (asset.status === 'READY') {
          clearInterval(interval);
          setFile((prev) => prev ? {
            ...prev,
            duration: asset.duration_seconds ? `${Math.floor(asset.duration_seconds / 60)}:${Math.round(asset.duration_seconds % 60).toString().padStart(2, '0')}` : '00:00',
            resolution: asset.width && asset.height ? `${asset.width}x${asset.height}` : prev.resolution,
            format: asset.video_codec ? asset.video_codec.toUpperCase() : prev.format,
          } : null);
        } else if (asset.status === 'FAILED') {
          clearInterval(interval);
          setProcessingError(asset.processing_error || 'Video transcoding failed. Media format might be unsupported.');
        }

        if (attempts >= maxAttempts) {
          clearInterval(interval);
        }
      } catch (err) {
        console.error('Polling error:', err);
      }
    }, 2000);
  };

  const handleRetryProcessing = async () => {
    if (!uploadedAssetId) return;
    try {
      setProcessingError(null);
      setProcessingStatus('QUEUED');
      setProcessingStage('re-queued');
      await api.reprocessMedia(uploadedAssetId);
      pollProcessingStatus(uploadedAssetId);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to reprocess asset';
      setProcessingError(msg);
    }
  };

  const addTag = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && tagInput.trim()) {
      e.preventDefault();
      if (!tags.includes(tagInput.trim())) {
        setTags([...tags, tagInput.trim()]);
      }
      setTagInput('');
    }
  };

  const removeTag = (t: string) => setTags(tags.filter((item) => item !== t));

  const addHashtag = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && hashtagInput.trim()) {
      e.preventDefault();
      const formatted = hashtagInput.startsWith('#') ? hashtagInput.trim() : `#${hashtagInput.trim()}`;
      if (!hashtags.includes(formatted)) {
        setHashtags([...hashtags, formatted]);
      }
      setHashtagInput('');
    }
  };

  const removeHashtag = (h: string) => setHashtags(hashtags.filter((item) => item !== h));

  const handleContinue = () => {
    // Store preliminary metadata in sessionStorage for composer
    if (typeof window !== 'undefined') {
      sessionStorage.setItem(
        'composer_seed',
        JSON.stringify({
          title: videoTitle,
          description,
          tags,
          hashtags,
          assetName,
          mediaAssetId: uploadedAssetId,
        })
      );
    }
    router.push('/composer');
  };

  return (
    <AppShell pageTitle="Upload Video">
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Upload Video</h1>
            <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
              Upload your high-resolution master asset once. You can customize per platform next.
            </p>
          </div>
          <button
            onClick={handleContinue}
            className="flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors"
          >
            <span>Next, Add Details</span>
            <ArrowRight className="h-4 w-4" />
          </button>
        </div>

        {/* Validation Error Alert */}
        {validationError && (
          <div className="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-xs text-red-700">
            <AlertCircle className="h-5 w-5 text-red-500 shrink-0" />
            <span className="font-medium">{validationError}</span>
          </div>
        )}

        {/* Upload Zone */}
        {!file && (
          <div
            onDragEnter={handleDrag}
            onDragLeave={handleDrag}
            onDragOver={handleDrag}
            onDrop={handleDrop}
            onClick={() => fileInputRef.current?.click()}
            className={`relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-10 sm:p-14 text-center cursor-pointer transition-all ${
              dragActive
                ? 'border-blue-500 bg-blue-50/50'
                : 'border-slate-300 bg-white hover:border-slate-400 hover:bg-slate-50/50'
            }`}
          >
            <input
              ref={fileInputRef}
              type="file"
              accept="video/*"
              className="hidden"
              onChange={handleFileInput}
            />

            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-blue-50 text-blue-600 mb-4 shadow-sm">
              <UploadCloud className="h-7 w-7" />
            </div>

            <h3 className="text-base font-semibold text-slate-900">
              Drag &amp; drop your video here
            </h3>
            <p className="text-xs text-slate-500 mt-1">or</p>

            <button
              type="button"
              className="mt-3 rounded-lg bg-white border border-slate-300 px-4 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
            >
              Choose File
            </button>

            <p className="text-[11px] text-slate-400 mt-4">
              Supports MP4, MOV, AVI, MKV, WebM — Max file size 2GB
            </p>

            {isUploading && (
              <div className="w-full max-w-xs mt-6 space-y-2">
                <div className="flex justify-between text-xs text-slate-600 font-medium">
                  <span>{uploadStatusText}</span>
                  <span>{uploadProgress}%</span>
                </div>
                <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                  <div
                    className="h-full bg-blue-600 transition-all duration-300 rounded-full"
                    style={{ width: `${uploadProgress}%` }}
                  />
                </div>
              </div>
            )}
          </div>
        )}

        {/* Uploaded Media Preview & Validation Card */}
        {file && (
          <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                  <FileVideo className="h-5 w-5" />
                </div>
                <div>
                  <h4 className="text-sm font-semibold text-slate-900">{file.name}</h4>
                  <div className="flex items-center gap-2 text-xs text-slate-500 mt-0.5">
                    <span>{file.size}</span>
                    <span>•</span>
                    <span>{file.duration}</span>
                    <span>•</span>
                    <span>{file.resolution}</span>
                    <span>•</span>
                    <span className="font-semibold text-slate-700">{file.format}</span>
                  </div>
                </div>
              </div>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                >
                  <RotateCcw className="h-3.5 w-3.5" />
                  <span>Replace Media</span>
                </button>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="video/*"
                  className="hidden"
                  onChange={handleFileInput}
                />
              </div>
            </div>

            {/* Asynchronous Processing State Banner */}
            {processingStatus && (
              <div className={`p-4 rounded-lg border text-xs flex items-center justify-between gap-3 ${
                processingStatus === 'READY'
                  ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                  : processingStatus === 'FAILED'
                  ? 'bg-red-50 border-red-200 text-red-800'
                  : 'bg-blue-50 border-blue-200 text-blue-800'
              }`}>
                <div className="flex items-center gap-2.5">
                  {processingStatus === 'READY' ? (
                    <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
                  ) : processingStatus === 'FAILED' ? (
                    <AlertCircle className="h-4 w-4 text-red-600 shrink-0" />
                  ) : (
                    <Loader2 className="h-4 w-4 text-blue-600 animate-spin shrink-0" />
                  )}
                  <div>
                    <div className="font-semibold capitalize">
                      {processingStatus === 'READY' ? 'FFmpeg Processing Complete' :
                       processingStatus === 'FAILED' ? 'Video Transcoding Failed' :
                       `Processing Video: ${processingStage || 'In Worker Queue'}`}
                    </div>
                    <div className="text-[11px] opacity-85 mt-0.5">
                      {processingStatus === 'READY'
                        ? `Probed ${mediaDetails?.width || 1920}x${mediaDetails?.height || 1080} • Codec: ${mediaDetails?.video_codec || 'h264'} / ${mediaDetails?.audio_codec || 'aac'} • Thumbnail generated.`
                        : processingStatus === 'FAILED'
                        ? (processingError || 'An error occurred during transcoding. You can retry safely.')
                        : 'FFmpeg queue worker is probing metadata and extracting representative thumbnail.'}
                    </div>
                  </div>
                </div>

                {processingStatus === 'FAILED' && (
                  <button
                    type="button"
                    onClick={handleRetryProcessing}
                    className="flex items-center gap-1.5 px-3 py-1.5 bg-red-600 text-white rounded-md font-medium text-xs hover:bg-red-700 transition"
                  >
                    <RefreshCw className="h-3.5 w-3.5" />
                    <span>Retry</span>
                  </button>
                )}
              </div>
            )}

            {/* Video Player Mock with Thumbnail */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 pt-1">
              <div className="relative aspect-video rounded-lg overflow-hidden bg-slate-900 flex items-center justify-center group shadow-inner">
                <img
                  src={mediaDetails?.thumbnail_url || "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600"}
                  alt="Video thumbnail"
                  className="w-full h-full object-cover opacity-85"
                />
                <div className="absolute inset-0 bg-black/20 flex items-center justify-center">
                  <div className="h-10 w-10 rounded-full bg-white/90 text-slate-900 flex items-center justify-center shadow-lg group-hover:scale-105 transition-transform">
                    <Play className="h-5 w-5 fill-slate-900 ml-0.5" />
                  </div>
                </div>
                <div className="absolute bottom-2 right-2 rounded bg-black/75 px-1.5 py-0.5 text-[10px] font-mono text-white font-semibold">
                  {file.duration}
                </div>
              </div>

              <div className="md:col-span-2 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-semibold text-slate-700">Video Thumbnail</span>
                  <button
                    type="button"
                    className="flex items-center gap-1 text-xs text-blue-600 hover:underline font-medium"
                  >
                    <ImageIcon className="h-3.5 w-3.5" />
                    <span>Upload Custom Thumbnail</span>
                  </button>
                </div>

                <div className="grid grid-cols-3 gap-2">
                  {[
                    'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=300',
                    'https://images.unsplash.com/photo-1550745165-9bc0b252726f?w=300',
                    'https://images.unsplash.com/photo-1518770660439-4636190af475?w=300',
                  ].map((thumb, idx) => (
                    <div
                      key={idx}
                      className={`relative aspect-video rounded border-2 overflow-hidden cursor-pointer ${
                        idx === 0 ? 'border-blue-600 ring-2 ring-blue-100' : 'border-slate-200 opacity-70 hover:opacity-100'
                      }`}
                    >
                      <img src={thumb} alt="Auto thumb" className="w-full h-full object-cover" />
                      {idx === 0 && (
                        <div className="absolute top-1 right-1 bg-blue-600 text-white rounded-full p-0.5">
                          <CheckCircle2 className="h-3 w-3" />
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Metadata Details Card */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
          <h3 className="text-sm font-semibold text-slate-900 border-b border-slate-100 pb-3">
            Initial Metadata
          </h3>

          <div className="space-y-4">
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Internal Asset Name
              </label>
              <input
                type="text"
                value={assetName}
                onChange={(e) => setAssetName(e.target.value)}
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600"
                placeholder="e.g. Master_Video_Final.mp4"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Video Title
              </label>
              <input
                type="text"
                value={videoTitle}
                onChange={(e) => setVideoTitle(e.target.value)}
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600"
                placeholder="Enter video title..."
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Description
              </label>
              <textarea
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600"
                placeholder="Enter description..."
              />
            </div>

            {/* Tags & Hashtags */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Tags (Press Enter)
                </label>
                <div className="flex flex-wrap items-center gap-1.5 rounded-lg border border-slate-300 bg-white p-2">
                  {tags.map((t) => (
                    <span
                      key={t}
                      className="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
                    >
                      {t}
                      <button type="button" onClick={() => removeTag(t)} className="text-slate-400 hover:text-slate-600">
                        <X className="h-3 w-3" />
                      </button>
                    </span>
                  ))}
                  <input
                    type="text"
                    value={tagInput}
                    onChange={(e) => setTagInput(e.target.value)}
                    onKeyDown={addTag}
                    placeholder="Add tag..."
                    className="flex-1 min-w-[80px] bg-transparent text-xs text-slate-900 focus:outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Hashtags (Press Enter)
                </label>
                <div className="flex flex-wrap items-center gap-1.5 rounded-lg border border-slate-300 bg-white p-2">
                  {hashtags.map((h) => (
                    <span
                      key={h}
                      className="inline-flex items-center gap-1 rounded bg-blue-50 px-2 py-0.5 text-xs text-blue-700 font-medium"
                    >
                      {h}
                      <button type="button" onClick={() => removeHashtag(h)} className="text-blue-400 hover:text-blue-600">
                        <X className="h-3 w-3" />
                      </button>
                    </span>
                  ))}
                  <input
                    type="text"
                    value={hashtagInput}
                    onChange={(e) => setHashtagInput(e.target.value)}
                    onKeyDown={addHashtag}
                    placeholder="#addhashtag..."
                    className="flex-1 min-w-[80px] bg-transparent text-xs text-slate-900 focus:outline-none"
                  />
                </div>
              </div>
            </div>
          </div>

          <div className="pt-4 border-t border-slate-100 flex justify-end">
            <button
              onClick={handleContinue}
              className="flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors"
            >
              <span>Next, Add Details</span>
              <ArrowRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
