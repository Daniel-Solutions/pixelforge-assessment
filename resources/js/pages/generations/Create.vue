<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import FieldLabel from '@/components/studio/FieldLabel.vue';
import SegmentedControl from '@/components/studio/SegmentedControl.vue';
import TokenTable from '@/components/studio/TokenTable.vue';
import { characterCount, estimate } from '@/lib/estimate';
import { formatUsd, toDecimalString } from '@/lib/money';
import { store } from '@/routes/generations';
import type { Option, Pricing, Quality, Size } from '@/types';

const props = defineProps<{
    pricing: Pricing | null;
    markupPercent: number;
    sizes: Option<Size>[];
    qualities: Option<Quality>[];
    limits: { promptCharacters: number; referenceImages: number };
}>();

const form = useForm({
    prompt: '',
    size: '1024x1024' as Size,
    quality: 'medium' as Quality,
    reference_images: [] as string[],
});

const characters = computed(() => characterCount(form.prompt));

const liveEstimate = computed(() =>
    props.pricing === null
        ? null
        : estimate(
              form.prompt,
              form.size,
              form.quality,
              form.reference_images.length,
              props.pricing,
              props.markupPercent,
          ),
);

const canSubmit = computed(
    () =>
        form.prompt.trim() !== '' &&
        characters.value <= props.limits.promptCharacters &&
        !form.processing,
);

function addReferenceImage(): void {
    form.reference_images.push('');
}

function removeReferenceImage(index: number): void {
    form.reference_images.splice(index, 1);
}

function referenceImageError(index: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `reference_images.${index}`
    ];
}

function submit(): void {
    form.submit(store());
}
</script>

<template>
    <Head title="Generate" />

    <form
        class="grid flex-1 lg:grid-cols-[336px_1fr]"
        novalidate
        @submit.prevent="submit"
    >
        <aside
            class="flex flex-col border-border bg-card bg-linear-to-t from-brand/10 to-card to-40% lg:min-h-[calc(100vh-4rem)] lg:border-r"
        >
            <h1 class="sr-only">Generate an image</h1>

            <div class="grid gap-5 p-5">
                <div>
                    <FieldLabel
                        for="prompt"
                        :counter="`${characters}/${limits.promptCharacters}`"
                        >Prompt</FieldLabel
                    >
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="6"
                        required
                        placeholder="Describe the image: subject, setting, light, style."
                        class="w-full resize-y rounded-md border border-input bg-control px-3 py-2.5 text-base shadow-control placeholder:text-subtle-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:text-sm"
                        :aria-invalid="form.errors.prompt ? true : undefined"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <div>
                    <FieldLabel
                        :counter="`${form.reference_images.length}/${limits.referenceImages}`"
                        >Reference images (optional)</FieldLabel
                    >
                    <div class="grid gap-2">
                        <div
                            v-for="(_, index) in form.reference_images"
                            :key="index"
                        >
                            <div class="flex gap-2">
                                <input
                                    v-model="form.reference_images[index]"
                                    type="url"
                                    inputmode="url"
                                    placeholder="https://example.com/style.png"
                                    :aria-label="`Reference image URL ${index + 1}`"
                                    class="min-w-0 flex-1 rounded-md border border-input bg-control px-3 py-2 text-sm shadow-control focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                />
                                <button
                                    type="button"
                                    class="rounded-md border border-input bg-control px-2 text-subtle-foreground shadow-control hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :aria-label="`Remove reference image ${index + 1}`"
                                    @click="removeReferenceImage(index)"
                                >
                                    <X class="size-4" />
                                </button>
                            </div>
                            <InputError :message="referenceImageError(index)" />
                        </div>
                        <button
                            v-if="
                                form.reference_images.length <
                                limits.referenceImages
                            "
                            type="button"
                            class="flex items-center gap-2 rounded-md border border-dashed border-input bg-control px-3 py-2 text-left text-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="addReferenceImage"
                        >
                            <Plus class="size-4" aria-hidden="true" /> Add image
                            URL
                        </button>
                        <InputError :message="form.errors.reference_images" />
                    </div>
                </div>

                <div>
                    <FieldLabel>Size</FieldLabel>
                    <SegmentedControl
                        v-model="form.size"
                        :options="sizes"
                        label="Size"
                    />
                </div>

                <div>
                    <FieldLabel>Quality</FieldLabel>
                    <SegmentedControl
                        v-model="form.quality"
                        :options="qualities"
                        label="Quality"
                    />
                </div>
            </div>

            <div
                class="sticky bottom-0 mt-auto flex items-center justify-between gap-3 border-t border-border bg-card/90 px-5 py-2.5 backdrop-blur"
            >
                <div aria-live="polite">
                    <template v-if="liveEstimate">
                        <p class="font-mono text-sm font-medium tabular-nums">
                            {{ formatUsd(liveEstimate.minPrice) }} –
                            {{ formatUsd(liveEstimate.maxPrice) }}
                        </p>
                        <p class="text-[11px] text-subtle-foreground">
                            Estimated price
                        </p>
                    </template>
                    <p v-else class="text-xs text-subtle-foreground">
                        Estimate unavailable
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="!canSubmit"
                    class="rounded-xl border border-black/10 bg-brand px-4.5 py-3 text-sm font-bold tracking-[0.025em] text-brand-foreground uppercase shadow-cta transition hover:brightness-105 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ form.processing ? 'Sending…' : 'Generate' }}
                </button>
            </div>
        </aside>

        <section class="px-4 py-5 lg:px-6" aria-labelledby="estimate-title">
            <h2 id="estimate-title" class="mb-4 text-sm font-semibold">
                Cost estimate
            </h2>
            <div class="max-w-2xl rounded-2xl border border-border bg-card p-5">
                <template v-if="liveEstimate">
                    <p class="mb-4 text-sm">
                        Estimated
                        <strong class="font-mono tabular-nums">{{
                            formatUsd(liveEstimate.minPrice)
                        }}</strong>
                        –
                        <strong class="font-mono tabular-nums">{{
                            formatUsd(liveEstimate.maxPrice)
                        }}</strong>
                    </p>
                    <details class="group">
                        <summary
                            class="cursor-pointer text-sm font-medium text-muted-foreground select-none hover:text-foreground"
                        >
                            Breakdown by token type
                        </summary>
                        <div class="mt-3">
                            <TokenTable
                                :min="liveEstimate.min"
                                :max="liveEstimate.max"
                            >
                                <tr>
                                    <td class="py-2 font-semibold">
                                        Your price
                                    </td>
                                    <td
                                        class="py-2 text-right font-mono font-semibold tabular-nums"
                                        :title="`$${toDecimalString(liveEstimate.minPrice)}`"
                                    >
                                        {{ formatUsd(liveEstimate.minPrice) }}
                                    </td>
                                    <td
                                        class="py-2 text-right font-mono font-semibold tabular-nums"
                                        :title="`$${toDecimalString(liveEstimate.maxPrice)}`"
                                    >
                                        {{ formatUsd(liveEstimate.maxPrice) }}
                                    </td>
                                </tr>
                            </TokenTable>
                        </div>
                    </details>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    Pixelforge pricing is unavailable right now, so we cannot
                    show an estimate. Try again in a minute.
                </p>
                <p class="mt-4 text-[13px] text-muted-foreground">
                    Final price is calculated from actual usage when the image
                    is ready. Failed generations are free.
                </p>
            </div>
        </section>
    </form>
</template>
