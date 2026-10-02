import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'OmniPublish — Studio Social Publishing Hub',
  description: 'Upload Once, Customize Once, Publish Everywhere, Track Everything across YouTube, TikTok, and Instagram.',
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className="h-full bg-slate-50 text-slate-900">
      <body className="h-full font-sans antialiased bg-[#F8FAFC] text-slate-900 selection:bg-blue-100 selection:text-blue-900">
        {children}
      </body>
    </html>
  );
}
