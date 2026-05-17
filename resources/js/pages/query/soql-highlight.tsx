import type React from 'react';

const KEYWORDS =
    /\b(SELECT|FROM|WHERE|AND|OR|GROUP BY|ORDER BY|DESC|ASC|LIMIT|LIKE|BETWEEN|AS|COUNT|NOT|IN|IS|NULL|HAVING|OFFSET)\b/g;

// Tiny SoQL syntax highlighter.
export function SoQLHighlight({ value }: { value: string }) {
    const tokens: React.ReactNode[] = [];
    let i = 0;
    const lines = value.split('\n');

    lines.forEach((line, li) => {
        const subs: { cls: string | null; txt: string }[] = [];
        const re = /'[^']*'|"[^"]*"|--[^\n]*|\b\d+(?:\.\d+)?\b/g;
        let m: RegExpExecArray | null;
        let last = 0;

        while ((m = re.exec(line))) {
            if (m.index > last) {
                subs.push({ cls: null, txt: line.slice(last, m.index) });
            }

            const tk = m[0];
            const cls = tk.startsWith('--')
                ? 'tk-cmt'
                : tk.startsWith("'") || tk.startsWith('"')
                  ? 'tk-str'
                  : 'tk-num';
            subs.push({ cls, txt: tk });
            last = m.index + tk.length;
        }

        if (last < line.length) {
            subs.push({ cls: null, txt: line.slice(last) });
        }

        subs.forEach((sub) => {
            if (sub.cls === null) {
                const parts = sub.txt.split(KEYWORDS);
                parts.forEach((p) => {
                    KEYWORDS.lastIndex = 0;

                    if (KEYWORDS.test(p)) {
                        tokens.push(
                            <span
                                key={i++}
                                className="text-[var(--rdw-orange)]"
                            >
                                {p}
                            </span>,
                        );
                    } else {
                        tokens.push(<span key={i++}>{p}</span>);
                    }

                    KEYWORDS.lastIndex = 0;
                });
            } else if (sub.cls === 'tk-str') {
                tokens.push(
                    <span key={i++} className="text-emerald-500">
                        {sub.txt}
                    </span>,
                );
            } else if (sub.cls === 'tk-num') {
                tokens.push(
                    <span key={i++} className="text-sky-400">
                        {sub.txt}
                    </span>,
                );
            } else {
                tokens.push(
                    <span key={i++} className="text-muted-foreground/70">
                        {sub.txt}
                    </span>,
                );
            }
        });

        if (li < lines.length - 1) {
            tokens.push(<span key={i++}>{'\n'}</span>);
        }
    });

    return <>{tokens}</>;
}

export function formatResponseBody(body: string): string {
    try {
        return JSON.stringify(JSON.parse(body), null, 2);
    } catch {
        return body;
    }
}
