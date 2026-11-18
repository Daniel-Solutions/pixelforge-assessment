<script setup lang="ts">
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Money } from '@/types';

defineProps<{ money: Money }>();
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    tabindex="0"
                    class="cursor-help font-mono tabular-nums underline decoration-border decoration-dotted underline-offset-4"
                    :aria-label="`${money.display} (exactly $${money.exact})`"
                    >{{ money.display }}</span
                >
            </TooltipTrigger>
            <TooltipContent>
                <span class="font-mono">Exactly ${{ money.exact }}</span>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
