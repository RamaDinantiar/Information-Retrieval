import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    GripVertical,
    ListOrdered,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import NgawasRecapOrderController from '@/actions/App/Http/Controllers/Asisten/NgawasRecapOrderController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { NgawasRecapData } from '@/types';

type Item = {
    userId: number;
    name: string;
    classes: { key: string; label: string }[];
};

function fromRecap(recap: NgawasRecapData): Item[] {
    return recap.assistants.map((a) => ({
        userId: a.userId,
        name: a.name,
        classes: a.rows
            .filter((r) => !r.placeholder)
            .map((r) => ({ key: r.key, label: r.label })),
    }));
}

function SortableRow({
    id,
    label,
    children,
}: {
    id: string;
    label: string;
    children: ReactNode;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`flex items-center gap-2 border bg-card px-2 py-1.5 text-sm ${isDragging ? 'z-10 shadow-md' : ''}`}
        >
            <button
                type="button"
                aria-label={`Geser ${label}`}
                className="cursor-grab touch-none text-muted-foreground"
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-4" />
            </button>
            {children}
        </li>
    );
}

/** Atur urutan asisten dan baris kelas pada rekap (seret, atau spasi + panah di keyboard). */
export function RecapOrderDialog({
    recap,
    month,
}: {
    recap: NgawasRecapData;
    month: string;
}) {
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<Item[]>([]);
    const [expanded, setExpanded] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const moveAssistant = (e: DragEndEvent) => {
        if (!e.over || e.active.id === e.over.id) {
            return;
        }

        setItems((list) =>
            arrayMove(
                list,
                list.findIndex((i) => `a${i.userId}` === e.active.id),
                list.findIndex((i) => `a${i.userId}` === e.over?.id),
            ),
        );
    };

    const moveClass = (userId: number) => (e: DragEndEvent) => {
        if (!e.over || e.active.id === e.over.id) {
            return;
        }

        setItems((list) =>
            list.map((i) =>
                i.userId !== userId
                    ? i
                    : {
                          ...i,
                          classes: arrayMove(
                              i.classes,
                              i.classes.findIndex((c) => c.key === e.active.id),
                              i.classes.findIndex((c) => c.key === e.over?.id),
                          ),
                      },
            ),
        );
    };

    const run = (visit: (done: () => void) => void) => {
        setBusy(true);
        visit(() => setBusy(false));
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setItems(fromRecap(recap));
                    setExpanded(null);
                }

                setOpen(next);
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <ListOrdered /> Atur Urutan
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Urutan rekap</DialogTitle>
                    <DialogDescription>
                        Seret untuk mengatur urutan asisten. Buka satu asisten
                        untuk mengatur urutan kelasnya. Berlaku untuk semua
                        bulan di semester ini; rekap Final tidak berubah.
                    </DialogDescription>
                </DialogHeader>

                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={moveAssistant}
                >
                    <SortableContext
                        items={items.map((i) => `a${i.userId}`)}
                        strategy={verticalListSortingStrategy}
                    >
                        <ol className="grid gap-1.5">
                            {items.map((item, index) => (
                                <SortableRow
                                    key={item.userId}
                                    id={`a${item.userId}`}
                                    label={item.name}
                                >
                                    <div className="grid min-w-0 flex-1 gap-1.5">
                                        <button
                                            type="button"
                                            className="flex items-center gap-1 text-left"
                                            aria-expanded={
                                                expanded === item.userId
                                            }
                                            onClick={() =>
                                                setExpanded(
                                                    expanded === item.userId
                                                        ? null
                                                        : item.userId,
                                                )
                                            }
                                        >
                                            {expanded === item.userId ? (
                                                <ChevronDown className="size-4 shrink-0" />
                                            ) : (
                                                <ChevronRight className="size-4 shrink-0" />
                                            )}
                                            <span className="w-6 shrink-0 text-xs text-muted-foreground">
                                                {index + 1}.
                                            </span>
                                            <span className="truncate font-medium">
                                                {item.name}
                                            </span>
                                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                                {item.classes.length} kelas
                                            </span>
                                        </button>
                                        {expanded === item.userId &&
                                            item.classes.length > 1 && (
                                                <DndContext
                                                    sensors={sensors}
                                                    collisionDetection={
                                                        closestCenter
                                                    }
                                                    onDragEnd={moveClass(
                                                        item.userId,
                                                    )}
                                                >
                                                    <SortableContext
                                                        items={item.classes.map(
                                                            (c) => c.key,
                                                        )}
                                                        strategy={
                                                            verticalListSortingStrategy
                                                        }
                                                    >
                                                        <ol className="grid gap-1">
                                                            {item.classes.map(
                                                                (c) => (
                                                                    <SortableRow
                                                                        key={
                                                                            c.key
                                                                        }
                                                                        id={
                                                                            c.key
                                                                        }
                                                                        label={
                                                                            c.label
                                                                        }
                                                                    >
                                                                        <span className="truncate">
                                                                            {
                                                                                c.label
                                                                            }
                                                                        </span>
                                                                    </SortableRow>
                                                                ),
                                                            )}
                                                        </ol>
                                                    </SortableContext>
                                                </DndContext>
                                            )}
                                        {expanded === item.userId &&
                                            item.classes.length <= 1 && (
                                                <p className="pl-5 text-xs text-muted-foreground">
                                                    Hanya {item.classes.length}{' '}
                                                    kelas, tidak ada yang perlu
                                                    diurutkan.
                                                </p>
                                            )}
                                    </div>
                                </SortableRow>
                            ))}
                        </ol>
                    </SortableContext>
                </DndContext>

                <DialogFooter className="gap-2 sm:justify-between">
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            run((done) =>
                                router.delete(
                                    NgawasRecapOrderController.destroy.url(),
                                    {
                                        data: { month },
                                        preserveScroll: true,
                                        onFinish: done,
                                        onSuccess: () => setOpen(false),
                                    },
                                ),
                            )
                        }
                    >
                        Kembalikan urutan otomatis
                    </Button>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={() =>
                                run((done) =>
                                    router.put(
                                        NgawasRecapOrderController.update.url(),
                                        {
                                            month,
                                            assistants: items.map((i) => ({
                                                userId: i.userId,
                                                classes: i.classes.map(
                                                    (c) => c.key,
                                                ),
                                            })),
                                        },
                                        {
                                            preserveScroll: true,
                                            onFinish: done,
                                            onSuccess: () => setOpen(false),
                                        },
                                    ),
                                )
                            }
                        >
                            Simpan urutan
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
