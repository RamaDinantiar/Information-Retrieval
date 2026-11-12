import type { RefObject } from 'react';
import { usePage } from '@inertiajs/react';
import { gsap, useGSAP } from '@/lib/gsap';
import { motion } from '@/lib/motion';

/** Bila hanya satu anak (mis. <form>), turun satu tingkat agar tiap kolom ikut bertahap. */
function bodyTargets(body: HTMLElement): HTMLElement[] {
    let items = Array.from(body.children).filter(
        (c): c is HTMLElement => c instanceof HTMLElement,
    );

    if (items.length === 1 && items[0].children.length > 1) {
        items = Array.from(items[0].children).filter(
            (c): c is HTMLElement => c instanceof HTMLElement,
        );
    }

    return items;
}

/**
 * Animasi halaman masuk/daftar/reset: kartu muncul lembut, lalu panel kiri dan
 * judul + kolom form menyusul bertahap. Diputar ulang tiap ganti halaman auth
 * (layout ini bisa tetap terpasang antar-halaman). Tanpa JS atau dengan
 * "kurangi gerakan" aktif, semuanya langsung tampil.
 */
export function useAuthMotion(root: RefObject<HTMLElement | null>) {
    const { url } = usePage();

    useGSAP(
        () => {
            const el = root.current;

            if (!el) {
                return;
            }

            const mm = gsap.matchMedia();

            mm.add('(prefers-reduced-motion: no-preference)', () => {
                const card = el.querySelector<HTMLElement>('[data-auth-card]');
                const left = Array.from(
                    el.querySelectorAll<HTMLElement>('[data-auth-left] > *'),
                );
                const head = el.querySelector<HTMLElement>('[data-auth-head]');
                const body = el.querySelector<HTMLElement>('[data-auth-body]');
                const right = [
                    ...(head ? [head] : []),
                    ...(body ? bodyTargets(body) : []),
                ];

                if (!card) {
                    return;
                }

                const tl = gsap.timeline({
                    defaults: { ease: motion.auth.ease },
                });

                tl.from(card, {
                    opacity: 0,
                    y: motion.auth.card.y,
                    duration: motion.auth.card.duration,
                    clearProps: 'opacity,transform',
                });

                if (left.length > 0) {
                    tl.from(
                        left,
                        {
                            opacity: 0,
                            y: motion.auth.item.y,
                            duration: motion.auth.item.duration,
                            stagger: motion.auth.item.stagger,
                            clearProps: 'opacity,transform',
                        },
                        0.15,
                    );
                }

                if (right.length > 0) {
                    tl.from(
                        right,
                        {
                            opacity: 0,
                            y: motion.auth.item.y,
                            duration: motion.auth.item.duration,
                            stagger: motion.auth.item.stagger,
                            clearProps: 'opacity,transform',
                        },
                        0.2,
                    );
                }
            });
        },
        { scope: root, dependencies: [url], revertOnUpdate: true },
    );
}
