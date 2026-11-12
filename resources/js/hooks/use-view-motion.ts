import type { RefObject } from 'react';
import { useRef } from 'react';
import { collectTargets } from '@/hooks/use-dashboard-motion';
import { gsap, useGSAP } from '@/lib/gsap';
import { motion } from '@/lib/motion';

/**
 * Animasi saat isi satu halaman berganti tanpa pindah halaman (mis. tombol
 * "Jadwal Saya" ↔ "Semua Jadwal di Lab"). Lewati render pertama: animasi
 * masuk halaman sudah ditangani `useDashboardMotion`. `scope` = pembungkus
 * isi yang berganti (beri `data-motion-group`); `viewKey` = penanda tampilan.
 */
export function useViewMotion(
    scope: RefObject<HTMLElement | null>,
    viewKey: string,
) {
    const previous = useRef(viewKey);

    useGSAP(
        () => {
            const el = scope.current;

            if (!el || previous.current === viewKey) {
                return;
            }

            previous.current = viewKey;

            const mm = gsap.matchMedia();

            mm.add('(prefers-reduced-motion: no-preference)', () => {
                const items = collectTargets(el);

                if (items.length === 0) {
                    return;
                }

                gsap.from(items, {
                    opacity: 0,
                    y: motion.view.y,
                    duration: motion.view.duration,
                    ease: motion.view.ease,
                    stagger: {
                        amount: Math.min(
                            motion.view.maxTotal,
                            motion.view.stagger * items.length,
                        ),
                    },
                    clearProps: 'opacity,transform',
                });
            });
        },
        { scope, dependencies: [viewKey] },
    );
}
