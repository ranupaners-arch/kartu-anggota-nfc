import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import {
    AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';

type Props = {
    title: string;
    description: string;
    href: string;
    method?: 'post' | 'put' | 'patch' | 'delete';
    data?: Record<string, string | number | number[] | null>;
    /** Dipanggil setelah server berhasil memproses (mis. kosongkan pilihan). */
    onSuccess?: () => void;
    confirmLabel?: string;
    destructive?: boolean;
    children: ReactNode;
    variant?: React.ComponentProps<typeof Button>['variant'];
    size?: React.ComponentProps<typeof Button>['size'];
};

/** Tombol dengan dialog konfirmasi sebelum mengirim aksi ke server. */
export function ConfirmAction({ title, description, href, method = 'post', data, onSuccess, confirmLabel = 'Lanjutkan', destructive, children, variant, size }: Props) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const run = () => {
        setBusy(true);
        router[method](href, data ?? {}, { preserveScroll: true, onSuccess, onFinish: () => { setBusy(false); setOpen(false); } });
    };

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant={variant ?? (destructive ? 'destructive' : 'outline')} size={size} />}>{children}</AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Batal</AlertDialogCancel>
                    <Button variant={destructive ? 'destructive' : 'default'} disabled={busy} onClick={run}>{busy ? 'Memproses…' : confirmLabel}</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
