# OmniPublish — UI & Design System Specification

## 1. Visual Identity & Design Philosophy

OmniPublish is a high-performance, studio-grade social publishing platform built for modern creators, agencies, and media teams. The visual identity embodies **Cyber-Minimalism, Tactile Glassmorphism, and Studio Precision**.

### Core Aesthetic Pillars:
1. **Studio Dark Mode by Default**: Deep slate and obsidian backgrounds (`#090D16`, `#0F172A`) that eliminate eye strain, elevate high-resolution video thumbnails, and create a premium pro-software atmosphere.
2. **Tactile Glassmorphism & Depth Hierarchy**: Layered semi-transparent surfaces with subtle 1px border glows (`border-white/[0.08]`), backdrop blur (`backdrop-blur-xl`), and ambient colored shadows.
3. **Harmonious Multi-Platform Accents**: Instead of conflicting brand colors, each platform has an isolated, carefully calibrated accent token:
   - **YouTube**: `#FF0000` / `#FF3333` glow
   - **TikTok**: Duo-tone Cyan `#00F2FE` & Coral Pink `#FE2C55`
   - **Instagram**: Radiant Sunset Gradient (`#833AB4` → `#FD1D1D` → `#FCB045`)
   - **LinkedIn**: Cerulean `#0A66C2`
   - **X (Twitter)**: Clean Electric `#1DA1F2` / Monochrome `#F3F4F6`
   - **Facebook**: Royal `#1877F2`
   - **Pinterest**: Crimson `#E60023`
4. **Fluid Micro-Interactions**: Hover lifts (`translate-y-[-2px]`), spring-physics drawer animations, shimmering skeleton loaders, and live pulsing status rings (`animate-pulse`) that provide instant physical feedback.
5. **No Monolithic Synchronous Blocks**: Real-time progress indicators, independent retry pills, and live feed preview mockups that mirror native mobile apps.

---

## 2. Design Tokens & Styling Foundations

### A. Color Palette
```css
:root {
  /* Surface & Base */
  --bg-canvas: #07090E;           /* Deepest obsidian background */
  --bg-surface: #0E131F;          /* Container surface */
  --bg-surface-elevated: #151D2E; /* Elevated cards and modals */
  --bg-surface-glass: rgba(15, 23, 42, 0.72);
  --border-subtle: rgba(255, 255, 255, 0.07);
  --border-hover: rgba(255, 255, 255, 0.15);
  --border-active: rgba(99, 102, 241, 0.5);

  /* Primary Brand (Omni Gradient) */
  --brand-primary: #6366F1;       /* Electric Indigo */
  --brand-violet: #8B5CF6;        /* Vivid Violet */
  --brand-cyan: #06B6D4;          /* Cyber Cyan */
  --brand-gradient: linear-gradient(135deg, #6366F1 0%, #8B5CF6 50%, #06B6D4 100%);

  /* Semantic Feedback */
  --status-success: #10B981;      /* Emerald */
  --status-warning: #F59E0B;      /* Warm Amber */
  --status-error: #EF4444;        /* Vivid Crimson */
  --status-info: #3B82F6;         /* Sky Blue */

  /* Text Hierarchy */
  --text-primary: #F8FAFC;        
  --text-secondary: #94A3B8;      
  --text-tertiary: #64748B;       
  --text-disabled: #475569;      

}
```

### B. Typography
- **Headings & Display**: `Outfit`, `Plus Jakarta Sans`, sans-serif (700 Bold / 600 Semi-Bold)
- **Body & Controls**: `Inter`, system-ui, sans-serif (400 Regular / 500 Medium)
- **Code, Timestamps & IDs**: `JetBrains Mono`, monospace (500 Medium)

### C. Glassmorphism Utilities
- `glass-panel`: `background: rgba(14, 19, 31, 0.75); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);`
- `glass-input`: `background: rgba(10, 13, 22, 0.6); border: 1px solid rgba(255, 255, 255, 0.1); transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);`

---

## 3. Core Screen Specifications

### 1. Unified Dashboard
- **Header**: Workspace switcher, quick global upload trigger (`+ New Post`), notification bell with unread badge count, and user avatar.
- **Metric Cards (Animated Glass Panels)**:
  1. *Connected Channels*: Active accounts count + health status pills (Green = Ready, Yellow = Reconnect Required).
  2. *Active Pipeline*: Number of videos currently rendering/uploading/processing with live animated spinning gradient border.
  3. *Today's Scheduled*: Timeline bar showing scheduled posts for the day.
  4. *Publishing Success Rate*: 30-day percentage ring chart with breakdown by platform.
- **Recent Publications Table / Cards**:
  - Video thumbnail with duration pill badge (`0:58`)
  - Title and Global snippet
  - Platform destination status pills with real-time state:
    - YouTube: `✓ Published` (Click opens live YouTube URL)
    - TikTok: `✓ Published`
    - Instagram: `⚠ Auth Expired` (Quick `[Reconnect]` modal action)
  - Batch action dropdown: Duplicate, Retry Failed, Download Analytics, Delete.

---

### 2. Social Accounts & Connection Hub
- **Card Grid Layout** for all supported platforms (YouTube, TikTok, Instagram, Facebook, LinkedIn, X, Pinterest).
- **Each Connection Card displays**:
  - Official platform icon with subtle ambient glow in platform brand color.
  - Channel / Account handle (`@creatorspace`, `Channel Title`).
  - Account avatar fetched directly from OAuth profile.
  - **Permissions & Scopes Pill Matrix**: e.g., `Video Upload: Enabled`, `Read Analytics: Enabled`, `Direct Publish: Enabled`.
  - **OAuth Health Indicator**:
    - `ACTIVE`: Last refreshed 14 mins ago.
    - `EXPIRING_SOON`: Amber badge with 1-click token renewal.
    - `DISCONNECTED`: Button `[Connect Account]` with PKCE secure flow.
  - **Account Controls**: `[Test Connection]`, `[Refresh Token]`, `[Disconnect Account]`.

---

### 3. Media Asset Library
- **Upload Zone**: Drag-and-drop zone with animated glowing drop target and upload speed indicator (`14.2 MB/s · 84%`).
- **Media Asset Grid**:
  - Video preview on hover (plays silent 3-second preview loop).
  - Video metadata badge: Resolution (`4K / 1080p`), Aspect Ratio (`9:16`, `16:9`, `1:1`), Duration (`00:45`), Size (`38.4 MB`), Audio Codec (`AAC`).
  - Processing status: `READY`, `PROCESSING (Transcoding 60fps)`, `FAILED`.
  - Quick action: `[Create Post with this Media]`.

---

### 4. Studio 3-Column Composer

The crown jewel of the OmniPublish experience:

```text
┌────────────────────────────────────────────────────────────────────────────────────────┐
│  OMNIPUBLISH STUDIO COMPOSER                             [Save Draft]  [Publish Now ▾] │
├──────────────────────┬───────────────────────────────────┬─────────────────────────────┤
│  COLUMN 1:           │  COLUMN 2:                        │  COLUMN 3:                  │
│  MEDIA STAGE         │  METADATA COMMAND CENTER          │  LIVE FEED PREVIEWS         │
│                      │                                   │                             │
│  ┌────────────────┐  │  Global Settings:                 │  Platform Selector Tabs:    │
│  │                │  │  Title:                           │  [ YouTube ] [ TikTok ]     │
│  │  Video Preview │  │  [ Modern AI Tooling Guide      ] │  [ Instagram ]              │
│  │  (9:16 Vertical│  │                                   │                             │
│  │   Player)      │  │  Global Description:              │  ┌───────────────────────┐  │
│  │                │  │  [ In this video we explore...  ] │  │ Real-time Interactive │  │
│  └────────────────┘  │                                   │  │ Native Mobile Mockup  │  │
│                      │  Smart Hashtags:                  │  │ (Shows exact title,   │  │
│  Thumbnail Selector  │  [#ai] [#tech] [#coding] [+ Add]  │  │  caption truncation,  │  │
│  [Frame 1][Frame 2]  │                                   │  │  and sound sticker)   │  │
│                      │  Destination Overrides:           │  └───────────────────────┘  │
│  Media Specs:        │  [▾ YouTube Options]              │                             │
│  1080x1920 · 60fps   │    - Made for Kids: No            │  Preflight Readiness Check: │
│  42.1 MB · MP4       │    - Tags: comma separated        │  ✓ YouTube: Ready           │
│                      │  [▾ TikTok Options]               │  ✓ TikTok: Ready (9:16)     │
│  Aspect Ratio Fixer: │    - Allow Comments: Yes          │  ⚠ Instagram: Caption over  │
│  [Auto-Fit / Pad]    │    - Sound: Original              │    2,200 chars limit        │
└──────────────────────┴───────────────────────────────────┴─────────────────────────────┘
```

#### Key Composer Features:
1. **Interactive Real-time Native Previews**: As the user types into global or platform-specific overrides, the Column 3 preview dynamically updates showing exact font sizes, line clamp cutoffs ("...more"), and platform UI overlays (likes, comments, share buttons).
2. **Preflight Live Validator**: Before clicking Publish, a live rule engine runs across all active platforms:
   - Validates character limits (TikTok caption max, YouTube title max 100 chars, Instagram hashtags limit).
   - Validates video length, codec, and aspect ratio suitability.
   - Blocks submission with clear inline fixes if any platform constraint is broken.
3. **Smart Scheduling Flyout**: Choose between instant broadcast (`Publish Now`) or time-optimized scheduling with timezone visualizer and creator's best time suggestions.

---

### 5. Live Publishing Monitor & Retries
- **Real-Time Pipeline Stepper**:
  - Global Batch ID: `#PUB-8492-X`
  - Visual status node tree:
    `Initiated` → `Media Validated` → `Dispatching to Platforms (3/3)`
- **Per-Platform Execution Cards**:
  - **YouTube**:
    - Status: `✓ SUCCESS (PUBLISHED)`
    - Remote Video ID: `dQw4w9WgXcQ`
    - Direct Button: `[View on YouTube ↗]`
  - **TikTok**:
    - Status: `⟳ PROCESSING BY TIKTOK`
    - Webhook polling heartbeat: *TikTok is auditing video content (estimated 30s)...*
  - **Instagram**:
    - Status: `✗ FAILED (AUTH_REQUIRED)`
    - Error Card: "Instagram OAuth token expired. Please reconnect your account to complete this publication without re-uploading."
    - Actions: `[Reconnect Account]` → `[Retry Instagram Only]` (Idempotent single-platform execution).

---

### 6. Interactive Content Calendar
- Views: Month, Week, Day timeline.
- Color-coded badges with platform micro-logos on scheduled posts.
- Drag-and-drop to reschedule post with immediate UTC validation and lock confirmation.

---

## 4. Accessibility & Responsive Rules

1. **Keyboard Accessibility**: Full focus visible outlines (`focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900`).
2. **Contrast Ratio**: All text and interactive elements satisfy **WCAG 2.1 AA** (minimum 4.5:1 for body, 3:1 for large display).
3. **Screen Reader Semantic Hierarchy**: Strict single `<h1>` per page, `<section aria-labelledby="...">`, `<dialog aria-modal="true">` with trap focus.
4. **Adaptive Breakpoints**:
   - `Desktop (> 1280px)`: Full 3-column composer with live preview sidecar.
   - `Laptop (1024px - 1279px)`: 2-column composer with floating preview toggle drawer.
   - `Tablet & Mobile (< 1024px)`: Single-column tabbed workflow (`[Media] · [Content] · [Preview] · [Publish]`).
