import { Head } from '@inertiajs/react';
import { AuthShell } from '@/components/auth-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';

export default function RegisterClosed() {
    return (
        <AuthShell title="Pendaftaran tidak tersedia">
            <Head title="Pendaftaran tidak tersedia" />
            <Alert><AlertDescription>Tautan pendaftaran ini sudah ditutup atau tidak berlaku. Minta tautan terbaru dari pengurus organisasi.</AlertDescription></Alert>
        </AuthShell>
    );
}
