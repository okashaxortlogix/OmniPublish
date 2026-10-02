# OmniPublish Design System Tokens & Style Guide

This document defines the production design tokens, CSS variables, utility classes, and component patterns for the OmniPublish frontend application.

---

## 1. Typography System

We import and use modern typography from Google Fonts:
- **Headings & Display**: `Outfit` (weights: 600, 700, 800)
- **Body & Controls**: `Plus Jakarta Sans` & `Inter` (weights: 400, 500, 600)
- **Code & Identifiers**: `JetBrains Mono` (weights: 400, 500)

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700&display=swap" rel="stylesheet">
```

---

## 2. Master CSS Variables

```css
:root {
  /* Canvas & Dark Foundations */
  --bg-primary: #07090e;
  --bg-secondary: #0d121f;
  --bg-tertiary: #131b2e;
  --bg-card: rgba(15, 23, 42, 0.75);
  --bg-card-hover: rgba(22, 33, 58, 0.85);
  --bg-glass: rgba(13, 18, 31, 0.65);

  /* Borders & Dividers */
  --border-subtle: rgba(255, 255, 255, 0.08);
  --border-muted: rgba(255, 255, 255, 0.14);
  --border-focus: #6366f1;
  --border-glow: rgba(99, 102, 241, 0.35);

  /* Brand Accents */
  --color-brand-indigo: #6366f1;
  --color-brand-violet: #8b5cf6;
  --color-brand-cyan: #06b6d4;
  --gradient-brand: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #06b6d4 100%);
  --gradient-brand-glow: linear-gradient(135deg, rgba(99, 102, 241, 0.25) 0%, rgba(139, 92, 246, 0.25) 100%);

  /* Social Platform Brand Tokens */
  --platform-youtube: #ff0000;
  --platform-youtube-glow: rgba(255, 0, 0, 0.25);
  
  --platform-tiktok: #fe2c55;
  --platform-tiktok-cyan: #25f4ee;
  --platform-tiktok-glow: rgba(254, 44, 85, 0.25);
  
  --platform-instagram-from: #833ab4;
  --platform-instagram-via: #fd1d1d;
  --platform-instagram-to: #fcb045;
  --gradient-instagram: linear-gradient(45deg, #833ab4, #fd1d1d, #fcb045);
  --platform-instagram-glow: rgba(225, 48, 108, 0.25);

  --platform-linkedin: #0a66c2;
  --platform-twitter: #1da1f2;
  --platform-facebook: #1877f2;
  --platform-pinterest: #e60023;

  /* Status Colors */
  --status-success: #10b981;
  --status-success-bg: rgba(16, 185, 129, 0.12);
  --status-success-border: rgba(16, 185, 129, 0.3);

  --status-warning: #f59e0b;
  --status-warning-bg: rgba(245, 158, 11, 0.12);
  --status-warning-border: rgba(245, 158, 11, 0.3);

  --status-error: #ef4444;
  --status-error-bg: rgba(239, 68, 68, 0.12);
  --status-error-border: rgba(239, 68, 68, 0.3);

  --status-info: #3b82f6;
  --status-info-bg: rgba(59, 130, 246, 0.12);
  --status-info-border: rgba(59, 130, 246, 0.3);

  /* Text Colors */
  --text-primary: #f8fafc;
  --text-secondary: #94a3b8;
  --text-muted: #64748b;
  --text-dimmed: #475569;
}
```

---

## 3. UI Component Patterns

### Button Styles
- **Primary Omni Action**:
  - Gradient background: `linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)`
  - Text: `#ffffff`, font-weight `600`
  - Subtle drop shadow: `0 4px 20px -2px rgba(99, 102, 241, 0.4)`
  - Hover: `transform: translateY(-1px); filter: brightness(1.1); box-shadow: 0 6px 24px -2px rgba(99, 102, 241, 0.6)`
  - Active: `transform: translateY(0px); filter: brightness(0.95)`

- **Secondary Glass Action**:
  - Background: `rgba(255, 255, 255, 0.05)`
  - Border: `1px solid rgba(255, 255, 255, 0.1)`
  - Hover: `background: rgba(255, 255, 255, 0.1); border-color: rgba(255, 255, 255, 0.2)`

- **Destructive Action**:
  - Background: `rgba(239, 68, 68, 0.1)`
  - Border: `1px solid rgba(239, 68, 68, 0.25)`
  - Text: `#ef4444`
  - Hover: `background: rgba(239, 68, 68, 0.2); border-color: rgba(239, 68, 68, 0.4)`

### Form Controls & Inputs
- Dark backdrop with inner inset shadow.
- Glowing border on focus: `border: 1px solid #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2)`.
- Live character limit counter pill in bottom-right corner.

### Status Indicators
- **Pulse Badge**: Small circular dot with an expanding ripple animation (`animate-ping`) when a job is actively uploading or publishing.
- **Success Tag**: Rounded pill with checkmark icon and emerald glow.
- **Retry Trigger**: Compact button with refresh icon allowing immediate idempotent re-execution.
