<script setup lang="ts">
import { ImageOff, LoaderCircle, TriangleAlert } from '@lucide/vue';
import type { Generation } from '@/types';

defineProps<{ generation: Generation; large?: boolean }>();
</script>

<template>
    <div
        class="flex shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-sunken text-center text-[11px] text-subtle-foreground"
        :class="large ? 'aspect-square w-full max-w-xl' : 'size-18'"
    >
        <a
            v-if="generation.image_url"
            :href="generation.image_url"
            target="_blank"
            rel="noopener noreferrer"
            class="size-full"
        >
            <img
                :src="generation.image_url"
                :alt="generation.prompt"
                class="size-full object-cover"
                loading="lazy"
            />
        </a>
        <span
            v-else-if="generation.image_expired"
            class="flex flex-col items-center gap-1 p-2"
        >
            <ImageOff class="size-4" aria-hidden="true" />
            <span v-if="large"
                >Image expired (Pixelforge keeps images for 24 hours)</span
            >
            <span v-else>Expired</span>
        </span>
        <TriangleAlert
            v-else-if="generation.status === 'failed'"
            class="size-5 text-destructive-foreground"
            aria-label="Failed"
        />
        <LoaderCircle
            v-else
            class="size-5 animate-spin"
            aria-label="Generating"
        />
    </div>
</template>
