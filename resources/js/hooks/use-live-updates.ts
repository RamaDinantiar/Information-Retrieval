import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const INTERVAL_MS = 5000;

/** Halaman yang tidak boleh dimuat ulang diam-diam (mis. penilaian yang sedang dikerjakan). */
const SKIP = [/\/grading\/\d+\/review/];

/** Pengguna sedang mengetik / membuka dialog: tunda pembaruan supaya tidak mengganggu. */
function userIsBusy(): boolean {
    if (document.querySelector('[role="dialog"], [role="alertdialog"]')) {
        return true;
    }

    const el = document.activeElement as HTMLElement | null;
    if (!el) return false;
    if (el.isContentEditable || el.tagName === 'TEXTAREA') return true;
    if (el.tagName === 'INPUT') {
        const type = (el as HTMLInputElement).type;
        return !['checkbox', 'radio', 'button', 'submit', 'file'].includes(
            type,
        );
    }
    return false;
}

/**
 * Pembaruan langsung tanpa refresh: menanyakan token perubahan ke server tiap
 * beberapa detik (hanya saat tab terlihat) dan memuat ulang data halaman bila
 * berubah. Status komponen (isian, scroll) tetap terjaga.
 */
export function useLiveUpdates() {
    useEffect(() => {
        let known: string | null = null;
        let latest: string | null = null;
        let visits = 0;
        let checking = false;
        let stopped = false;

        const offStart = router.on('start', () => (visits += 1));
        const offFinish = router.on('finish', () => {
            visits = Math.max(0, visits - 1);
        });

        const flush = () => {
            if (latest === null || latest === known) return;
            if (visits > 0 || userIsBusy()) return;
            if (SKIP.some((re) => re.test(window.location.pathname))) {
                known = latest;
                return;
            }
            known = latest;
            router.reload();
        };

        const check = async () => {
            if (stopped || checking || document.hidden) return;
            checking = true;
            try {
                const res = await fetch('/live/version', {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                if (res.ok) {
                    const { v } = (await res.json()) as { v: string };
                    latest = v;
                    if (known === null) known = v;
                }
            } catch {
                // Offline / server mati: coba lagi pada giliran berikutnya.
            } finally {
                checking = false;
            }
            flush();
        };

        void check();
        const timer = window.setInterval(() => void check(), INTERVAL_MS);
        const onVisible = () => {
            if (!document.hidden) void check();
        };
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);

        return () => {
            stopped = true;
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onVisible);
            offStart();
            offFinish();
        };
    }, []);
}
