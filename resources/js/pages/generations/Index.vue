<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import GenerationImage from '@/components/studio/GenerationImage.vue';
import MoneyAmount from '@/components/studio/MoneyAmount.vue';
import StatusBadge from '@/components/studio/StatusBadge.vue';
import { usePendingPoll } from '@/composables/usePendingPoll';
import { formatDateTime } from '@/lib/format';
import { create, show } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    hasPending: boolean;
}>();

usePendingPoll(() => props.hasPending, ['generations']);
</script>

<template>
    <Head title="History" />

    <div class="px-4 py-5 lg:px-6">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-sm font-semibold">History</h1>
            <p
                v-if="hasPending"
                class="text-xs text-subtle-foreground"
                aria-live="polite"
            >
                Updating live…
            </p>
        </div>

        <div
            v-if="generations.data.length === 0"
            class="rounded-2xl border border-border bg-sunken px-6 py-16 text-center"
        >
            <Sparkles
                class="mx-auto mb-3 size-6 text-brand-ink"
                aria-hidden="true"
            />
            <p class="font-semibold">No generations yet</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Write a prompt and press Generate. Every result shows up here
                first.
            </p>
            <Link
                :href="create()"
                class="mt-4 inline-block rounded-md bg-primary px-3 py-2 text-xs font-semibold text-primary-foreground"
                >Generate an image</Link
            >
        </div>

        <ul v-else class="grid gap-2.5">
            <li
                v-for="generation in generations.data"
                :key="generation.id"
                class="grid grid-cols-[72px_1fr] gap-x-4 gap-y-2 rounded-2xl border border-border bg-card p-3 md:grid-cols-[72px_1fr_120px_170px_120px] md:items-center"
            >
                <GenerationImage :generation="generation" />

                <div class="min-w-0">
                    <Link
                        :href="show(generation.id)"
                        class="line-clamp-2 font-medium hover:underline focus-visible:underline"
                        >{{ generation.prompt }}</Link
                    >
                    <p class="text-xs text-subtle-foreground">
                        {{ generation.size_label }} ·
                        {{ generation.quality_label }} ·
                        <time :datetime="generation.created_at">{{
                            formatDateTime(generation.created_at)
                        }}</time>
                    </p>
                    <p
                        v-if="generation.status === 'failed'"
                        class="mt-0.5 text-xs text-destructive-foreground"
                    >
                        {{ generation.status_detail }}
                    </p>
                    <p
                        v-else-if="!generation.is_final"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        {{ generation.status_detail }}
                    </p>
                </div>

                <div class="col-start-2 md:col-start-auto">
                    <StatusBadge :status="generation.status" />
                </div>

                <div class="col-start-2 text-xs md:col-start-auto">
                    <span class="text-subtle-foreground">Est. </span>
                    <MoneyAmount :money="generation.estimate.min" /> –
                    <MoneyAmount :money="generation.estimate.max" />
                </div>

                <div
                    class="col-start-2 text-sm md:col-start-auto md:text-right"
                >
                    <template
                        v-if="generation.was_charged && generation.charged"
                    >
                        <span class="text-xs text-subtle-foreground md:hidden"
                            >Charged
                        </span>
                        <strong
                            ><MoneyAmount :money="generation.charged"
                        /></strong>
                    </template>
                    <span
                        v-else-if="generation.status === 'failed'"
                        class="text-xs font-medium"
                        >Not charged</span
                    >
                    <span v-else class="text-xs text-subtle-foreground"
                        >Charged when ready</span
                    >
                </div>
            </li>
        </ul>

        <nav
            v-if="generations.meta.last_page > 1"
            class="mt-4 flex items-center justify-between text-sm"
            aria-label="Pagination"
        >
            <Link
                v-if="generations.links.prev"
                :href="generations.links.prev"
                class="rounded-md border border-input bg-control px-3 py-1.5 shadow-control"
                >Newer</Link
            >
            <span v-else />
            <span class="text-subtle-foreground"
                >Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}</span
            >
            <Link
                v-if="generations.links.next"
                :href="generations.links.next"
                class="rounded-md border border-input bg-control px-3 py-1.5 shadow-control"
                >Older</Link
            >
            <span v-else />
        </nav>
    </div>
</template>
