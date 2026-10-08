import { useState } from 'react';
import { Button } from '@/components/ui/button';
import type { Product } from '../types';

type Props = {
    products: Product[];
    readOnly: boolean;
    onRestore: (p: Product) => Promise<void>;
};

/**
 * Archived products, folded away under the table. Restoring one puts it back on the marketplace
 * against the competitors it had when it was archived.
 */
export function ArchivedProducts({ products, readOnly, onRestore }: Props) {
    const [busy, setBusy] = useState<number | null>(null);

    if (products.length === 0) {
        return null;
    }

    return (
        <details className="mt-4 border-t border-border pt-3 text-sm">
            <summary className="cursor-pointer text-muted-foreground select-none">
                Archived ({products.length})
            </summary>
            <ul className="mt-2 divide-y divide-border">
                {products.map((p) => (
                    <li
                        key={p.id}
                        className="flex items-center justify-between gap-3 py-2"
                    >
                        <div className="min-w-0">
                            <div className="truncate">{p.title}</div>
                            <div className="text-xs text-muted-foreground">
                                {p.sku} · {p.asin}
                            </div>
                        </div>
                        {!readOnly && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8 shrink-0"
                                disabled={busy !== null}
                                aria-label={`Restore ${p.title}`}
                                onClick={async () => {
                                    setBusy(p.id);
                                    try {
                                        await onRestore(p);
                                    } finally {
                                        setBusy(null);
                                    }
                                }}
                            >
                                {busy === p.id ? 'Restoring…' : 'Restore'}
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </details>
    );
}
