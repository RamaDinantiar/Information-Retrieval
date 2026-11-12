import type { RefObject } from 'react';
import { gsap, ScrollTrigger, useGSAP } from '@/lib/gsap';
import { motion } from '@/lib/motion';

/**
 * Elemen yang dianimasikan: anak langsung `root`; bila anak itu grid, anak-anak
 * grid-nya (maks. 2 tingkat) agar kartu muncul satu per satu, bukan sebaris.
 */
export function collectTargets(parent: Element, depth = 0): HTMLElement[] {
    return Array.from(parent.children).flatMap((child) => {
        if (!(child instanceof HTMLElement)) {
            return [];
        }

        return (child.classList.contains('grid') ||
            child.hasAttribute('data-motion-group')) &&
            depth < 2
            ? collectTargets(child, depth + 1)
            : [child];
    });
}

/**
 * Animasi masuk dashboard (lembut, sekali per kunjungan):
 * - kartu & panel naik 18px sambil memudar, bertahap;
 * - bar progres (`data-grow`) tergambar dari kiri.
 * Elemen yang di bawah layar dianimasikan saat mulai terlihat. Tanpa JS atau
 * dengan "kurangi gerakan" aktif, semua langsung tampil apa adanya.
 */
export function useDashboardMotion(root: RefObject<HTMLElement | null>) {
    useGSAP(
        () => {
            const el = root.current;

            if (!el) {
                return;
            }

            const mm = gsap.matchMedia();

            mm.add('(prefers-reduced-motion: no-preference)', () => {
                const cards = collectTargets(el);
                const bars = Array.from(
                    el.querySelectorAll<HTMLElement>('[data-grow]'),
                );

                if (cards.length > 0) {
                    gsap.set(cards, { opacity: 0, y: motion.reveal.y });
                    ScrollTrigger.batch(cards, {
                        start: 'top 100%',
                        once: true,
                        onEnter: (batch) =>
                            gsap.to(batch, {
                                opacity: 1,
                                y: 0,
                                duration: motion.reveal.duration,
                                ease: motion.reveal.ease,
                                stagger: motion.reveal.stagger,
                                overwrite: true,
                                clearProps: 'opacity,transform',
                            }),
                    });
                }

                if (bars.length > 0) {
                    gsap.set(bars, {
                        scaleX: 0,
                        transformOrigin: 'left center',
                    });
                    ScrollTrigger.batch(bars, {
                        start: 'top 100%',
                        once: true,
                        onEnter: (batch) =>
                            gsap.to(batch, {
                                scaleX: 1,
                                duration: motion.bar.duration,
                                delay: motion.bar.delay,
                                ease: motion.bar.ease,
                                stagger: motion.bar.stagger,
                                overwrite: true,
                                clearProps: 'transform',
                            }),
                    });
                }
            });
        },
        { scope: root },
    );
}
