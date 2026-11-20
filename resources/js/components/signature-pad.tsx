import { Eraser } from 'lucide-react';
import type { PointerEvent } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** Resolusi tetap kanvas; tampilan menyesuaikan lebar kontainer (koordinat diskalakan). */
const WIDTH = 600;
const HEIGHT = 220;
const INK = '#111111';

type Point = { x: number; y: number };

/**
 * Kanvas tanda tangan (mouse, sentuh, pena). Hasilnya PNG transparan lewat
 * `onChange` (null bila kosong); server memangkas dan merapikannya. Tanpa
 * dependensi tambahan.
 */
export function SignaturePad({
    onChange,
    className,
}: {
    onChange: (dataUrl: string | null) => void;
    className?: string;
}) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const last = useRef<Point | null>(null);
    const [empty, setEmpty] = useState(true);

    const context = useCallback(() => {
        const ctx = canvas.current?.getContext('2d') ?? null;

        if (ctx) {
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.lineWidth = 4;
            ctx.strokeStyle = INK;
            ctx.fillStyle = INK;
        }

        return ctx;
    }, []);

    useEffect(() => {
        context();
    }, [context]);

    const point = (event: PointerEvent<HTMLCanvasElement>): Point => {
        const rect = event.currentTarget.getBoundingClientRect();

        return {
            x: ((event.clientX - rect.left) * WIDTH) / rect.width,
            y: ((event.clientY - rect.top) * HEIGHT) / rect.height,
        };
    };

    const start = (event: PointerEvent<HTMLCanvasElement>) => {
        event.currentTarget.setPointerCapture(event.pointerId);
        const p = point(event);
        const ctx = context();
        last.current = p;
        ctx?.beginPath();
        ctx?.arc(p.x, p.y, 2, 0, Math.PI * 2); // titik tunggal tetap terlihat
        ctx?.fill();
    };

    const move = (event: PointerEvent<HTMLCanvasElement>) => {
        const from = last.current;

        if (!from) {
            return;
        }

        const to = point(event);
        const mid = { x: (from.x + to.x) / 2, y: (from.y + to.y) / 2 };
        const ctx = context();
        ctx?.beginPath();
        ctx?.moveTo(from.x, from.y);
        ctx?.quadraticCurveTo(from.x, from.y, mid.x, mid.y);
        ctx?.lineTo(to.x, to.y);
        ctx?.stroke();
        last.current = to;
    };

    const end = () => {
        if (!last.current) {
            return;
        }

        last.current = null;
        setEmpty(false);
        onChange(canvas.current?.toDataURL('image/png') ?? null);
    };

    const clear = () => {
        canvas.current?.getContext('2d')?.clearRect(0, 0, WIDTH, HEIGHT);
        last.current = null;
        setEmpty(true);
        onChange(null);
    };

    return (
        <div className={cn('grid gap-2', className)}>
            <div className="relative border bg-white">
                <canvas
                    ref={canvas}
                    width={WIDTH}
                    height={HEIGHT}
                    role="img"
                    aria-label="Area menggambar tanda tangan. Jika sulit menggambar, gunakan tab Unggah gambar."
                    className="block h-auto w-full cursor-crosshair touch-none"
                    onPointerDown={start}
                    onPointerMove={move}
                    onPointerUp={end}
                    onPointerCancel={end}
                />
                {empty && (
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-muted-foreground"
                    >
                        Goreskan tanda tangan di sini
                    </span>
                )}
                <span
                    aria-hidden
                    className="pointer-events-none absolute right-4 bottom-10 left-4 border-b border-dashed border-slate-300"
                />
            </div>
            <div>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={empty}
                    onClick={clear}
                >
                    <Eraser /> Hapus coretan
                </Button>
            </div>
        </div>
    );
}
