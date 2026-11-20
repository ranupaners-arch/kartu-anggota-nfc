import { type ReactNode } from 'react';
import { Copyright } from '@/components/copyright';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/** Kerangka halaman tamu (masuk, lupa password, atur ulang): logo + satu kartu di tengah. */
export function AuthShell({ title, description, children, wide = false }: { title: string; description?: ReactNode; children: ReactNode; wide?: boolean }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-muted/40 p-4">
            <div className={`flex w-full ${wide ? 'max-w-md' : 'max-w-sm'} flex-col items-center gap-6`}>
                <div className="flex flex-col items-center gap-3">
                    <img src="/brand/logo-2d.svg" alt="Logo Kartu Anggota" className="size-20 drop-shadow-md" />
                    <span className="text-lg font-semibold tracking-tight">Kartu Anggota</span>
                </div>
                <Card className="w-full">
                    <CardHeader>
                        <CardTitle className="text-xl">{title}</CardTitle>
                        {description && <CardDescription>{description}</CardDescription>}
                    </CardHeader>
                    <CardContent>{children}</CardContent>
                </Card>
                <Copyright className="text-center" />
            </div>
        </div>
    );
}
