<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, RotateCcw } from '@lucide/vue';
import GenerationImage from '@/components/studio/GenerationImage.vue';
import MoneyAmount from '@/components/studio/MoneyAmount.vue';
import StatusBadge from '@/components/studio/StatusBadge.vue';
import TokenTable from '@/components/studio/TokenTable.vue';
import { usePendingPoll } from '@/composables/usePendingPoll';
import { formatDateTime } from '@/lib/format';
import { index, retry } from '@/routes/generations';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: { data: Generation };
    hasPending: boolean;
}>();

usePendingPoll(() => props.hasPending, ['generation']);

const retryForm = useForm({});

function retryGeneration(): void {
    retryForm.submit(retry(props.generation.data.id));
}
</script>

<template>
    <Head title="Generation" />

    <div
        class="grid gap-6 px-4 py-5 lg:grid-cols-[minmax(0,1fr)_420px] lg:px-6"
    >
        <div class="grid content-start gap-4">
            <Link
                :href="index()"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-4" aria-hidden="true" /> History
            </Link>
            <GenerationImage :generation="generation.data" large />
        </div>

        <aside class="grid content-start gap-4">
            <div class="rounded-2xl border border-border bg-card p-5">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h1 class="text-sm font-semibold">Generation</h1>
                    <StatusBadge :status="generation.data.status" />
                </div>
                <p class="mb-3 text-sm whitespace-pre-line">
                    {{ generation.data.prompt }}
                </p>
                <p
                    class="text-sm"
                    :class="
                        generation.data.status === 'failed'
                            ? 'text-destructive-foreground'
                            : 'text-muted-foreground'
                    "
                    aria-live="polite"
                >
                    {{ generation.data.status_detail }}
                </p>
                <button
                    v-if="generation.data.can_retry"
                    type="button"
                    :disabled="retryForm.processing"
                    class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-2 text-xs font-semibold text-primary-foreground disabled:opacity-50"
                    @click="retryGeneration"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" /> Retry as a
                    new generation
                </button>

                <dl class="mt-4 grid grid-cols-2 gap-y-2 text-[13px]">
                    <dt class="text-muted-foreground">Size</dt>
                    <dd class="text-right">
                        {{ generation.data.size_label }} ({{
                            generation.data.size
                        }})
                    </dd>
                    <dt class="text-muted-foreground">Quality</dt>
                    <dd class="text-right">
                        {{ generation.data.quality_label }}
                    </dd>
                    <dt class="text-muted-foreground">Created</dt>
                    <dd class="text-right">
                        {{ formatDateTime(generation.data.created_at) }}
                    </dd>
                    <template v-if="generation.data.completed_at">
                        <dt class="text-muted-foreground">Completed</dt>
                        <dd class="text-right">
                            {{ formatDateTime(generation.data.completed_at) }}
                        </dd>
                    </template>
                    <template v-if="generation.data.image_url_expires_at">
                        <dt class="text-muted-foreground">
                            Image available until
                        </dt>
                        <dd class="text-right">
                            {{
                                formatDateTime(
                                    generation.data.image_url_expires_at,
                                )
                            }}
                        </dd>
                    </template>
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd class="text-right">
                        <MoneyAmount :money="generation.data.estimate.min" /> –
                        <MoneyAmount :money="generation.data.estimate.max" />
                    </dd>
                    <dt class="font-semibold">You paid</dt>
                    <dd class="text-right font-semibold">
                        <MoneyAmount
                            v-if="
                                generation.data.was_charged &&
                                generation.data.charged
                            "
                            :money="generation.data.charged"
                        />
                        <span v-else-if="generation.data.status === 'failed'"
                            >Nothing</span
                        >
                        <span v-else class="font-normal text-subtle-foreground"
                            >When ready</span
                        >
                    </dd>
                </dl>
            </div>

            <div class="rounded-2xl border border-border bg-card p-5">
                <h2 class="mb-2 text-sm font-semibold">
                    Tokens: estimated vs. actual
                </h2>
                <TokenTable
                    :min="generation.data.tokens.estimated_min"
                    :max="generation.data.tokens.estimated_max"
                    :actual="generation.data.tokens.actual"
                />
            </div>

            <div
                v-if="generation.data.reference_images.length > 0"
                class="rounded-2xl border border-border bg-card p-5"
            >
                <h2 class="mb-2 text-sm font-semibold">Reference images</h2>
                <ul class="grid gap-1 text-[13px]">
                    <li
                        v-for="url in generation.data.reference_images"
                        :key="url"
                        class="truncate"
                    >
                        <a
                            :href="url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline"
                            >{{ url }}</a
                        >
                    </li>
                </ul>
            </div>
        </aside>
    </div>
</template>
