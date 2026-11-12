import { CheckCircle2, CloudUpload, Trash2, TriangleAlert } from 'lucide-react';
import { useRef, useState } from 'react';
import type { DragEvent } from 'react';
import { Button } from '@/components/ui/button';
import { formatFileSize } from '@/lib/files';
import { cn } from '@/lib/utils';

type Format = 'pdf' | 'zip';

type DraftFile = { id: number; name: string; size: number; url: string };

const BADGE: Record<Format, string> = {
    pdf: 'bg-[#e5322d]',
    zip: 'bg-primary-700',
};

type Row = {
    key: string;
    name: string;
    size: number;
    ext: string | undefined;
    /** Berkas dari draf (hasil Unsend), bukan pilihan baru. */
    draft?: DraftFile;
    file?: File;
};

/**
 * Area unggah: klik atau seret & lepas, boleh beberapa berkas sekaligus.
 * Berkas yang sudah dipilih ditumpuk sebagai daftar di bawah area unggah
 * (nama, ukuran, status, tombol hapus per berkas). Input `files[]` dan
 * `keep_draft_files[]` ikut dikirim oleh <Form>.
 */
export function AnswerDropzone({
    formats,
    limits,
    invalid,
    onChange,
    draftFiles = [],
    maxFiles = 5,
}: {
    formats: Format[];
    /** Batas ukuran per format, MB. */
    limits: Record<Format, number>;
    invalid?: boolean;
    /** Dipanggil saat daftar berkas baru berubah. */
    onChange?: (files: File[]) => void;
    /** Berkas dari pengumpulan yang dibatalkan (Unsend); ikut terkirim kecuali dihapus. */
    draftFiles?: DraftFile[];
    maxFiles?: number;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [files, setFiles] = useState<File[]>([]);
    const [kept, setKept] = useState<number[]>(draftFiles.map((f) => f.id));
    const [dragging, setDragging] = useState(false);

    const sync = (next: File[]) => {
        if (input.current) {
            const transfer = new DataTransfer();
            next.forEach((f) => transfer.items.add(f));
            input.current.files = transfer.files;
        }

        setFiles(next);
        onChange?.(next);
    };

    const add = (incoming: File[]) => {
        const fresh = incoming.filter(
            (f) => !files.some((x) => x.name === f.name && x.size === f.size),
        );
        sync([...files, ...fresh]);
    };

    const drop = (event: DragEvent) => {
        event.preventDefault();
        setDragging(false);
        add([...event.dataTransfer.files]);
    };

    /** Buka berkas yang baru dipilih (belum diunggah) di tab baru. */
    const openLocal = (file?: File) => {
        if (!file) {
            return;
        }

        const url = URL.createObjectURL(file);
        window.open(url, '_blank', 'noopener');
        setTimeout(() => URL.revokeObjectURL(url), 60_000);
    };

    const extOf = (name: string) => name.split('.').pop()?.toLowerCase();

    const rows: Row[] = [
        ...draftFiles
            .filter((f) => kept.includes(f.id))
            .map((f) => ({
                key: `d${f.id}`,
                name: f.name,
                size: f.size,
                ext: extOf(f.name),
                draft: f,
            })),
        ...files.map((f, i) => ({
            key: `n${i}-${f.name}-${f.size}`,
            name: f.name,
            size: f.size,
            ext: extOf(f.name),
            file: f,
        })),
    ];
    const hasZip = rows.some((r) => r.ext === 'zip');

    const problemOf = (row: Row): string | null => {
        if (row.draft) {
            return null;
        }

        const ext = row.ext as Format | undefined;

        if (!ext || !formats.includes(ext)) {
            return `Format harus ${formats.map((f) => f.toUpperCase()).join(' atau ')}.`;
        }

        if (row.size > limits[ext] * 1024 * 1024) {
            return `Ukuran melebihi ${limits[ext]} MB.`;
        }

        return null;
    };

    const tooMany = rows.length > maxFiles;
    const zipMixed = hasZip && rows.length > 1;

    const removeRow = (row: Row) => {
        if (row.draft) {
            const id = row.draft.id;
            setKept((k) => k.filter((x) => x !== id));

            return;
        }

        sync(files.filter((f) => f !== row.file));
    };

    return (
        <div className="grid gap-4">
            {kept.map((id) => (
                <input
                    key={id}
                    type="hidden"
                    name="keep_draft_files[]"
                    value={id}
                />
            ))}
            <label
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={drop}
                className={cn(
                    'grid cursor-pointer justify-items-center gap-1.5 border-2 border-dashed px-6 py-12 text-center transition-colors has-focus-visible:ring-[3px] has-focus-visible:ring-ring/40',
                    dragging
                        ? 'border-primary-500 bg-primary-50'
                        : invalid
                          ? 'border-danger'
                          : 'border-[#bfbfbf] hover:bg-primary-50/50',
                )}
            >
                <input
                    ref={input}
                    id="answer-file"
                    name="files[]"
                    type="file"
                    multiple
                    accept={formats.map((f) => `.${f}`).join(',')}
                    onChange={(event) => add([...(event.target.files ?? [])])}
                    aria-invalid={invalid}
                    className="sr-only"
                />
                <CloudUpload
                    className="size-12 text-muted-foreground"
                    aria-hidden
                />
                <span className="text-base font-medium">
                    Pilih berkas atau seret &amp; lepas di sini
                </span>
                <span className="text-xs text-muted-foreground">
                    {formats
                        .map((f) => `${f} · maks ${limits[f]} MB`)
                        .join(' — ')}
                    {formats.includes('pdf') &&
                        ` · hingga ${maxFiles} berkas PDF`}
                </span>
                <span className="mt-1 border bg-white px-3 py-1 text-xs font-medium">
                    Telusuri berkas
                </span>
            </label>

            {rows.length > 0 && (
                <div className="grid gap-2">
                    <p className="text-sm font-bold">
                        {rows.length} berkas siap dikirim
                    </p>
                    <ul className="grid gap-2">
                        {rows.map((row) => {
                            const problem = problemOf(row);
                            const ext = row.ext as Format | undefined;

                            return (
                                <li
                                    key={row.key}
                                    className="flex items-center gap-4 border p-4"
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'flex size-12 shrink-0 items-center justify-center text-sm font-bold text-white uppercase',
                                            ext && formats.includes(ext)
                                                ? BADGE[ext]
                                                : 'bg-muted-foreground',
                                        )}
                                    >
                                        {row.ext?.slice(0, 4) ?? '?'}
                                    </span>
                                    <div className="grid min-w-0 flex-1 gap-1">
                                        {row.draft ? (
                                            <a
                                                href={row.draft.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="truncate text-sm font-bold underline"
                                            >
                                                {row.name}
                                            </a>
                                        ) : (
                                            <a
                                                href="#"
                                                onClick={(event) => {
                                                    event.preventDefault();
                                                    openLocal(row.file);
                                                }}
                                                className="truncate text-sm font-bold underline"
                                            >
                                                {row.name}
                                            </a>
                                        )}
                                        <span className="flex flex-wrap items-center gap-2 text-xs">
                                            <span className="font-mono">
                                                {formatFileSize(row.size)}
                                            </span>
                                            <span aria-hidden>•</span>
                                            {problem ? (
                                                <span className="flex items-center gap-1 text-danger">
                                                    <TriangleAlert
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                    {problem}
                                                </span>
                                            ) : row.draft ? (
                                                <span className="text-muted-foreground">
                                                    Dari pengumpulan sebelumnya
                                                    · ikut terkirim kecuali
                                                    dihapus
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-1 text-success">
                                                    <CheckCircle2
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                    Siap Dikirim
                                                </span>
                                            )}
                                        </span>
                                    </div>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        onClick={() => removeRow(row)}
                                        aria-label={`Hapus ${row.name}`}
                                    >
                                        <Trash2 />
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                    {tooMany && (
                        <p
                            role="alert"
                            className="flex items-center gap-1 text-sm text-danger"
                        >
                            <TriangleAlert className="size-4" aria-hidden />
                            Maksimal {maxFiles} berkas. Hapus beberapa berkas.
                        </p>
                    )}
                    {zipMixed && (
                        <p
                            role="alert"
                            className="flex items-center gap-1 text-sm text-danger"
                        >
                            <TriangleAlert className="size-4" aria-hidden />
                            File ZIP harus dikumpulkan sendiri. Hapus berkas
                            lain atau kumpulkan PDF saja.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
