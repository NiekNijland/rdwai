import { useEffect, useMemo, useState } from 'react';

function shuffleArray<T>(input: readonly T[]): T[] {
    const out = [...input];

    for (let i = out.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [out[i], out[j]] = [out[j], out[i]];
    }

    return out;
}

// Animates a textarea placeholder through a shuffled list of phrases. Pauses
// when the tab is hidden so we're not burning battery / scheduling timers
// off-screen.
export function useTypewriterPlaceholder(
    phrases: readonly string[],
    active: boolean,
    fallback: string,
): string {
    const shuffled = useMemo(() => shuffleArray(phrases), [phrases]);
    const [index, setIndex] = useState(0);
    const [count, setCount] = useState(0);
    const [phase, setPhase] = useState<'typing' | 'holding' | 'deleting'>(
        'typing',
    );
    const [visible, setVisible] = useState(true);

    useEffect(() => {
        if (typeof document === 'undefined') {
            return;
        }

        const update = () => setVisible(!document.hidden);
        update();

        document.addEventListener('visibilitychange', update);

        return () => document.removeEventListener('visibilitychange', update);
    }, []);

    useEffect(() => {
        if (!active || !visible || shuffled.length === 0) {
            return;
        }

        const current = shuffled[index % shuffled.length];
        let delay: number;

        if (phase === 'typing') {
            if (count < current.length) {
                delay = 18 + Math.random() * 18;
            } else {
                delay = 1400;
            }
        } else if (phase === 'holding') {
            delay = 1400;
        } else {
            delay = 8 + Math.random() * 10;
        }

        const timer = window.setTimeout(() => {
            if (phase === 'typing') {
                if (count < current.length) {
                    setCount(count + 1);
                } else {
                    setPhase('deleting');
                }
            } else if (phase === 'deleting') {
                if (count > 0) {
                    setCount(count - 1);
                } else {
                    setIndex((i) => (i + 1) % shuffled.length);
                    setPhase('typing');
                }
            }
        }, delay);

        return () => window.clearTimeout(timer);
    }, [active, visible, shuffled, index, count, phase]);

    if (!active || shuffled.length === 0) {
        return fallback;
    }

    const current = shuffled[index % shuffled.length];

    return current.slice(0, count) + '▍';
}
