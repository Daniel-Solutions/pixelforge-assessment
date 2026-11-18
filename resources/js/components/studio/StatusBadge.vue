<script setup lang="ts">
import { computed } from 'vue';
import type { GenerationStatus } from '@/types';

const props = defineProps<{ status: GenerationStatus }>();

const styles: Record<
    GenerationStatus,
    { label: string; dot: string; text: string }
> = {
    queued: {
        label: 'Queued',
        dot: 'bg-subtle-foreground',
        text: 'text-foreground',
    },
    processing: {
        label: 'Processing',
        dot: 'bg-warning animate-pulse',
        text: 'text-foreground',
    },
    succeeded: {
        label: 'Succeeded',
        dot: 'bg-brand-ink',
        text: 'text-foreground',
    },
    failed: {
        label: 'Failed',
        dot: 'bg-destructive-foreground',
        text: 'text-destructive-foreground',
    },
};

const style = computed(() => styles[props.status]);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border border-input bg-control px-2.5 py-0.5 text-xs font-semibold shadow-control"
        :class="style.text"
    >
        <span
            class="size-1.5 rounded-full"
            :class="style.dot"
            aria-hidden="true"
        />
        {{ style.label }}
    </span>
</template>
