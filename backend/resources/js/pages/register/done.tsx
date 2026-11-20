import { Head } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { AuthShell } from '@/components/auth-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';

export default function RegisterDone({ organization }: { organization: string }) {
    return (
        <AuthShell title="Pendaftaran terkirim" description={organization}>
            <Head title="Pendaftaran terkirim" />
            <Alert role="status">
                <CircleCheck />
                <AlertDescription>
                    Terima kasih! Data Anda sudah diterima dan menunggu persetujuan pengurus. Setelah disetujui, pengurus akan
                    memberikan kartu anggota dan akun untuk app/portal anggota.
                </AlertDescription>
            </Alert>
        </AuthShell>
    );
}
