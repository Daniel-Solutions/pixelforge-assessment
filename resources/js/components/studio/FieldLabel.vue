<script setup lang="ts">
defineProps<{ for?: string; counter?: string }>();
</script>

<template>
    <div
        class="mb-1.5 flex items-baseline justify-between text-[11px] tracking-[0.08em] uppercase"
    >
        <label :for="$props.for" class="text-muted-foreground"><slot /></label>
        <span v-if="counter" class="text-subtle-foreground tabular-nums">{{
            counter
        }}</span>
    </div>
</template>
