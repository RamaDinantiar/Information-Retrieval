import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { CountUp } from '@/components/count-up';

/** Figma "24 Pre/Post-Test belum dinilai": angka besar + label, seluruh kartu bisa diklik. */
export function MetricCard({
    value,
    label,
    href,
}: {
    value: number;
    label: string;
    href: string;
}) {
    return (
        <Link
            href={href}
            prefetch
            className="group relative flex h-full min-h-36 flex-col justify-end gap-1 border bg-card p-5 transition-[transform,box-shadow] duration-200 ease-out hover:-translate-y-0.5 hover:shadow-md motion-reduce:transition-none motion-reduce:hover:translate-y-0 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <span className="absolute top-4 right-4 flex size-9 items-center justify-center rounded-full bg-primary-200 text-primary-900 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5">
                <ArrowUpRight className="size-5" aria-hidden />
            </span>
            <span className="text-[3rem] leading-none font-bold text-primary">
                <CountUp value={value} />
            </span>
            <span className="text-h3 font-bold text-primary">{label}</span>
        </Link>
    );
}
