<script setup lang="ts" generic="T extends string">
import type { Option } from '@/types';

defineProps<{ options: Option<T>[]; label: string }>();
const model = defineModel<T>({ required: true });
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="label"
        class="flex rounded-md border border-input bg-secondary p-0.5"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="model === option.value"
            class="flex-1 rounded-sm border px-1 py-1.5 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="
                model === option.value
                    ? 'border-input bg-control text-foreground shadow-control'
                    : 'border-transparent text-subtle-foreground hover:text-foreground'
            "
            @click="model = option.value"
        >
            {{ option.label }}
        </button>
    </div>
</template>
