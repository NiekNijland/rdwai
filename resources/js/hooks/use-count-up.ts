import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';

function subscribeReducedMotion(callback: () => void): () => void {
    if (typeof window === 'undefined') {
        return () => {};
    }

    const mql = window.matchMedia(REDUCED_MOTION);
    mql.addEventListener('change', callback);

    return () => mql.removeEventListener('change', callback);
}

function getReducedMotionSnapshot(): boolean {
    return window.matchMedia(REDUCED_MOTION).matches;
}

function getReducedMotionServerSnapshot(): boolean {
    return false;
}

// Tweens from the previous `target` to the new `target` whenever `target`
// changes. Renders `target` directly (no animation) when reduced motion is on
// or the value isn't finite. Initial render returns `target`, so SSR and the
// client first paint always agree.
export function useCountUp(target: number, durationMs = 900): number {
    const reducedMotion = useSyncExternalStore(
        subscribeReducedMotion,
        getReducedMotionSnapshot,
        getReducedMotionServerSnapshot,
    );
    const [value, setValue] = useState(target);
    const fromRef = useRef(target);
    const rafRef = useRef<number | null>(null);

    useEffect(() => {
        if (!Number.isFinite(target) || reducedMotion) {
            fromRef.current = target;

            return;
        }

        const from = fromRef.current;

        if (from === target) {
            return;
        }

        let startedAt: number | null = null;

        const tick = (timestamp: number) => {
            if (startedAt === null) {
                startedAt = timestamp;
            }

            const elapsed = timestamp - startedAt;
            const t = Math.min(1, elapsed / durationMs);
            // easeOutCubic
            const eased = 1 - Math.pow(1 - t, 3);

            setValue(from + (target - from) * eased);

            if (t < 1) {
                rafRef.current = window.requestAnimationFrame(tick);
            } else {
                fromRef.current = target;
            }
        };

        rafRef.current = window.requestAnimationFrame(tick);

        return () => {
            if (rafRef.current !== null) {
                window.cancelAnimationFrame(rafRef.current);
            }

            fromRef.current = target;
        };
    }, [target, durationMs, reducedMotion]);

    if (!Number.isFinite(target) || reducedMotion) {
        return target;
    }

    return value;
}
