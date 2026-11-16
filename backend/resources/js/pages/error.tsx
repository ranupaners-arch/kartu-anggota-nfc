import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';

export default function ErrorPage({ status, message }: { status: number; message: string }) {
    const title = status === 403 ? 'Tidak punya akses' : status === 404 ? 'Tidak ditemukan' : 'Terjadi kesalahan';
    return (
        <AppLayout title={title}>
            <Head title={title} />
            <div className="mx-auto flex max-w-md flex-col items-center gap-3 py-16 text-center">
                <div className="text-5xl font-semibold text-muted-foreground">{status}</div>
                <h2 className="text-lg font-semibold">{title}</h2>
                <p className="text-sm text-muted-foreground">{message}</p>
                <Button render={<Link href="/" />}>Kembali ke Beranda</Button>
            </div>
        </AppLayout>
    );
}
