<script setup lang="ts">
import { formatTokens } from '@/lib/format';
import type { TokenCounts, TokenType } from '@/types';

defineProps<{
    min: TokenCounts;
    max: TokenCounts;
    actual?: TokenCounts | null;
}>();

const rows: { type: TokenType; label: string }[] = [
    { type: 'input_text_tokens', label: 'Prompt text (±15%)' },
    { type: 'input_image_tokens', label: 'Reference images (765 each)' },
    { type: 'output_tokens', label: 'Image output' },
    { type: 'reasoning_tokens', label: 'Reasoning' },
];
</script>

<template>
    <table class="w-full text-[13px]">
        <thead>
            <tr class="border-b border-border text-muted-foreground">
                <th class="py-2 text-left font-medium">Tokens</th>
                <th class="py-2 text-right font-medium">Min</th>
                <th class="py-2 text-right font-medium">Max</th>
                <th
                    v-if="actual !== undefined"
                    class="py-2 text-right font-medium"
                >
                    Actual
                </th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="row in rows"
                :key="row.type"
                class="border-b border-border"
            >
                <td class="py-2">{{ row.label }}</td>
                <td class="py-2 text-right font-mono tabular-nums">
                    {{ formatTokens(min[row.type]) }}
                </td>
                <td class="py-2 text-right font-mono tabular-nums">
                    {{ formatTokens(max[row.type]) }}
                </td>
                <td
                    v-if="actual !== undefined"
                    class="py-2 text-right font-mono tabular-nums"
                >
                    {{ actual ? formatTokens(actual[row.type]) : '—' }}
                </td>
            </tr>
            <slot />
        </tbody>
    </table>
</template>
