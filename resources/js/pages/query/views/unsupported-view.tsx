import { ShieldAlert } from 'lucide-react';

// The refusal text itself comes from the LLM via `result.plan.explanation` and
// is already rendered in the card header. This view only contributes the
// visual marker so the user can recognise the refusal at a glance — no body
// copy to keep us from saying the same thing twice in slightly different
// words.
export function UnsupportedView() {
    return (
        <div className="flex items-center justify-center py-4">
            <ShieldAlert className="h-8 w-8 text-neutral-400 dark:text-neutral-500" />
        </div>
    );
}
