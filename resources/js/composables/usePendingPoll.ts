import { usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';

const INTERVAL_MS = 3000;

/**
 * Reloads the given props every 3 s, only while something on the page is still pending.
 */
export function usePendingPoll(
    hasPending: () => boolean,
    only: string[],
): void {
    const { start, stop } = usePoll(
        INTERVAL_MS,
        { only: [...only, 'hasPending'] },
        { autoStart: false },
    );

    watch(hasPending, (pending) => (pending ? start() : stop()), {
        immediate: true,
    });
}
