import { useRef } from 'react';
import { gsap, useGSAP } from '@/lib/gsap';
import { motion } from '@/lib/motion';
import { cn } from '@/lib/utils';

function decimalsOf(value: number): number {
    return Number.isInteger(value)
        ? 0
        : Math.min(2, String(value).split('.')[1]?.length ?? 0);
}

/**
 * Angka KPI yang menghitung naik dari 0 saat pertama terlihat (sekali).
 * Teks akhir sudah dirender oleh React, jadi tanpa JS / "kurangi gerakan"
 * angka tetap benar dan tidak berubah-ubah.
 */
export function CountUp({
    value,
    suffix = '',
    className,
}: {
    value: number;
    suffix?: string;
    className?: string;
}) {
    const ref = useRef<HTMLSpanElement>(null);
    const decimals = decimalsOf(value);

    useGSAP(
        () => {
            const el = ref.current;

            if (!el || value === 0) {
                return;
            }

            const mm = gsap.matchMedia();

            mm.add('(prefers-reduced-motion: no-preference)', () => {
                const state = { n: 0 };
                const paint = () => {
                    el.textContent = `${state.n.toFixed(decimals)}${suffix}`;
                };

                paint();
                gsap.to(state, {
                    n: value,
                    duration: motion.count.duration,
                    ease: motion.count.ease,
                    delay: motion.count.delay,
                    onUpdate: paint,
                    scrollTrigger: {
                        trigger: el,
                        start: 'top 100%',
                        once: true,
                    },
                });
            });
        },
        { scope: ref },
    );

    return (
        <span ref={ref} className={cn('tabular-nums', className)}>
            {`${value.toFixed(decimals)}${suffix}`}
        </span>
    );
}
