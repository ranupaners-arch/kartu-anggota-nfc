import { Head, Link } from '@inertiajs/react';
import { type TwoFactor, TwoFactorCard } from '@/components/two-factor-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import FinanceLayout from '@/layouts/finance-layout';

export default function DuesSecurity({ twoFactor, required }: { twoFactor: TwoFactor; required: boolean }) {
    return (
        <FinanceLayout title="Keamanan akses keuangan">
            <Head title="Keamanan akses keuangan" />
            <div className="grid max-w-3xl gap-4">
                {required && !twoFactor.enabled && (
                    <Alert>
                        <AlertTitle>Aktifkan 2FA untuk membuka iuran</AlertTitle>
                        <AlertDescription>Bendahara dan sekretaris wajib memakai verifikasi dua langkah. Setelah aktif, kode juga diminta saat masuk ke app dan web.</AlertDescription>
                    </Alert>
                )}
                <TwoFactorCard tf={twoFactor} base="/iuran/2fa" />
                {twoFactor.enabled && <div><Button render={<Link href="/iuran" />}>Buka iuran</Button></div>}
            </div>
        </FinanceLayout>
    );
}
