import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { IdleSessionGuard } from '@/components/idle-session-guard';
import { LogoutConfirmDialog } from '@/components/logout-confirm-dialog';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell>
            {/* WCAG 2.4.1: pengguna keyboard bisa melewati sidebar. */}
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-bold focus:text-primary-700 focus:shadow-lg focus:ring-2 focus:ring-primary-500"
            >
                Lompat ke konten
            </a>
            <AppSidebar />
            <AppContent
                id="main-content"
                tabIndex={-1}
                className="min-w-0 overflow-x-clip outline-none"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/* PRD 7.3: konten maks. 1440px */}
                <div className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col">
                    {children}
                </div>
            </AppContent>
            <IdleSessionGuard />
            <LogoutConfirmDialog />
        </AppShell>
    );
}
