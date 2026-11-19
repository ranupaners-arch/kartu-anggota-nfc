import { cloneElement, isValidElement, useId, type ReactElement, type ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';

type ControlProps = { id?: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string };

const CONTROLS: unknown[] = [Input, NativeSelect, Textarea];

/**
 * Label + kolom isian + petunjuk/galat. Bila isinya satu kolom (Input/NativeSelect/Textarea), label
 * dihubungkan ke kolom itu (klik label memfokuskan kolom, pembaca layar membacakan namanya), kolom
 * ditandai aria-invalid saat ada galat, dan petunjuk/galat dibacakan lewat aria-describedby.
 * Isi lain (mis. sekumpulan kotak centang) dibungkus role="group" yang diberi nama oleh label.
 */
export function FormField({ label, error, hint, htmlFor, children }: { label: string; error?: string; hint?: string; htmlFor?: string; children: ReactNode }) {
    const uid = useId();
    const isControl = isValidElement(children) && CONTROLS.includes(children.type);
    const control = isControl ? (children as ReactElement<ControlProps>) : null;
    const id = htmlFor ?? control?.props.id ?? `${uid}-control`;
    const labelId = `${uid}-label`;
    const hintId = hint && !error ? `${uid}-hint` : undefined;
    const errorId = error ? `${uid}-error` : undefined;
    const describedBy = [control?.props['aria-describedby'], hintId, errorId].filter(Boolean).join(' ') || undefined;

    return (
        <div className="grid gap-1.5">
            <Label id={labelId} htmlFor={control || htmlFor ? id : undefined}>{label}</Label>
            {control ? (
                cloneElement(control, { id, 'aria-invalid': error ? true : control.props['aria-invalid'], 'aria-describedby': describedBy })
            ) : htmlFor ? (
                children
            ) : (
                <div role="group" aria-labelledby={labelId} aria-describedby={describedBy}>{children}</div>
            )}
            {hintId && <p id={hintId} className="text-xs text-muted-foreground">{hint}</p>}
            {errorId && <p id={errorId} className="text-xs text-destructive">{error}</p>}
        </div>
    );
}
