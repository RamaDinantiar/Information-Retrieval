import { Form, Head } from '@inertiajs/react';
import {
    Clock,
    FileSpreadsheet,
    HardDrive,
    Mail,
    PenLine,
    UserPlus,
} from 'lucide-react';
import type { ReactNode } from 'react';
import SystemSettingController from '@/actions/App/Http/Controllers/Admin/SystemSettingController';
import InputError from '@/components/input-error';
import { ApproverCard } from '@/components/ngawas-recap/approver-card';
import type { Approver } from '@/components/ngawas-recap/approver-card';
import { TemplateCard } from '@/components/ngawas-recap/template-card';
import type { NgawasTemplateInfo } from '@/components/ngawas-recap/template-card';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useRef } from 'react';
import { useDashboardMotion } from '@/hooks/use-dashboard-motion';

type Settings = {
    idle_minutes: number;
    limit_pdf_mb: number;
    limit_zip_mb: number;
    limit_image_mb: number;
    registration_open: boolean;
    registration_domains: string;
    email_notifications: boolean;
};

function Section({
    icon: Icon,
    title,
    description,
    children,
}: {
    icon: typeof Clock;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="grid gap-4 border bg-card p-5 md:grid-cols-[16rem_1fr]">
            <div className="flex gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center bg-primary-100 text-primary-800">
                    <Icon className="size-4" aria-hidden />
                </span>
                <div>
                    <h2 className="font-bold">{title}</h2>
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                </div>
            </div>
            <div className="grid gap-4">{children}</div>
        </section>
    );
}

function NumberField({
    name,
    label,
    suffix,
    value,
    min,
    max,
    error,
}: {
    name: keyof Settings;
    label: string;
    suffix: string;
    value: number;
    min: number;
    max: number;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <div className="flex items-center gap-2">
                <Input
                    id={name}
                    name={name}
                    type="number"
                    min={min}
                    max={max}
                    required
                    defaultValue={value}
                    className="w-28"
                />
                <span className="text-sm text-muted-foreground">{suffix}</span>
            </div>
            <InputError message={error} />
        </div>
    );
}

/** Admin › Pengaturan Sistem (FR-ADM-10). */
export default function SystemSettings({
    settings,
    approvers,
    template,
}: {
    settings: Settings;
    approvers: Approver[];
    template: NgawasTemplateInfo;
}) {
    const root = useRef<HTMLDivElement>(null);
    useDashboardMotion(root);

    return (
        <>
            <Head title="Pengaturan Sistem" />
            <div ref={root} className="flex flex-col gap-7 p-4 md:p-6 lg:p-8">
                <PageHeader
                    title="Pengaturan Sistem"
                    description="Durasi sesi idle, dan batas ukuran unggahan."
                />

                <Form
                    {...SystemSettingController.update.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Section
                                icon={Clock}
                                title="Sesi"
                                description="Pengguna keluar otomatis setelah tidak aktif (kecuali memilih Ingat Saya)."
                            >
                                <NumberField
                                    name="idle_minutes"
                                    label="Durasi idle"
                                    suffix="menit"
                                    value={settings.idle_minutes}
                                    min={5}
                                    max={240}
                                    error={errors.idle_minutes}
                                />
                            </Section>
                            <Section
                                icon={UserPlus}
                                title="Pendaftaran Akun"
                                description="Praktikan dapat mendaftar sendiri hanya dengan email kampus, lalu wajib memverifikasi email."
                            >
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="hidden"
                                        name="registration_open"
                                        value="0"
                                    />
                                    <Checkbox
                                        name="registration_open"
                                        value="1"
                                        defaultChecked={
                                            settings.registration_open
                                        }
                                    />{' '}
                                    Buka pendaftaran mandiri praktikan
                                </label>
                                <div className="grid gap-2">
                                    <Label htmlFor="registration_domains">
                                        Domain email yang diizinkan
                                    </Label>
                                    <Input
                                        id="registration_domains"
                                        name="registration_domains"
                                        required
                                        maxLength={255}
                                        defaultValue={
                                            settings.registration_domains
                                        }
                                        placeholder="itpln.ac.id"
                                        className="max-w-sm font-mono"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Pisahkan dengan koma bila lebih dari
                                        satu. Sub-domain tidak otomatis
                                        diizinkan.
                                    </p>
                                    <InputError
                                        message={errors.registration_domains}
                                    />
                                </div>
                            </Section>
                            <Section
                                icon={Mail}
                                title="Notifikasi Email"
                                description="Semua notifikasi penting (tugas, nilai, tenggat, laporan ngawas, pengumuman, dll.) juga dikirim ke email akun, untuk semua peran. Dikirim lewat antrean; pastikan worker antrean berjalan."
                            >
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="hidden"
                                        name="email_notifications"
                                        value="0"
                                    />
                                    <Checkbox
                                        name="email_notifications"
                                        value="1"
                                        defaultChecked={
                                            settings.email_notifications
                                        }
                                    />{' '}
                                    Kirim notifikasi ke email
                                </label>
                                <p className="text-xs text-muted-foreground">
                                    Matikan sementara bila SMTP bermasalah.
                                    Notifikasi lonceng di aplikasi tetap
                                    berjalan.
                                </p>
                            </Section>
                            <Section
                                icon={HardDrive}
                                title="Batas Ukuran File"
                                description="Berlaku untuk unggahan baru (NFR-F02)."
                            >
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <NumberField
                                        name="limit_pdf_mb"
                                        label="PDF"
                                        suffix="MB"
                                        value={settings.limit_pdf_mb}
                                        min={1}
                                        max={50}
                                        error={errors.limit_pdf_mb}
                                    />
                                    <NumberField
                                        name="limit_zip_mb"
                                        label="ZIP"
                                        suffix="MB"
                                        value={settings.limit_zip_mb}
                                        min={1}
                                        max={100}
                                        error={errors.limit_zip_mb}
                                    />
                                    <NumberField
                                        name="limit_image_mb"
                                        label="Gambar"
                                        suffix="MB"
                                        value={settings.limit_image_mb}
                                        min={1}
                                        max={20}
                                        error={errors.limit_image_mb}
                                    />
                                </div>
                            </Section>
                            <div className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />} Simpan
                                    Pengaturan
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <Section
                    icon={PenLine}
                    title="Pengesah Rekap Ngawas"
                    description="Dua pengesah yang namanya dan tanda tangannya dicetak di Rekap Jam Ngawas saat Final. Tanpa NIP. Rekap yang sudah Final tidak berubah bila pengesah diganti."
                >
                    <div className="grid gap-4 lg:grid-cols-2">
                        {approvers.map((approver) => (
                            <ApproverCard
                                key={approver.index}
                                approver={approver}
                            />
                        ))}
                    </div>
                </Section>

                <Section
                    icon={FileSpreadsheet}
                    title="Template Rekap Ngawas"
                    description="File Excel kampus yang diisi saat ekspor Draft dan Final. Ganti bila kampus merilis versi baru."
                >
                    <TemplateCard template={template} />
                </Section>
            </div>
        </>
    );
}
