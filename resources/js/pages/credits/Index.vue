<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MoneyAmount from '@/components/studio/MoneyAmount.vue';
import { formatDateTime } from '@/lib/format';
import { store } from '@/routes/credits';
import { show } from '@/routes/generations';
import type { Money } from '@/types';

const props = defineProps<{
    purchaseToken: string;
    packages: { value: number; amount: Money }[];
    entries: {
        id: number;
        type: 'purchase' | 'generation_charge';
        amount: Money;
        generation: { id: string; prompt: string } | null;
        created_at: string;
    }[];
}>();

const user = computed(() => usePage().props.auth.user);

/** The server issues one token per page view: a double click or a resubmitted form buys only once. */
const form = useForm({ package: 0, purchase_token: props.purchaseToken });

function buy(packageValue: number): void {
    form.package = packageValue;
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: (page) => {
            form.purchase_token = String(page.props.purchaseToken);
        },
    });
}
</script>

<template>
    <Head title="Credits" />

    <div class="grid max-w-3xl gap-6 px-4 py-5 lg:px-6">
        <section class="rounded-2xl border border-border bg-card p-5">
            <h1 class="text-sm font-semibold">Credits</h1>
            <dl class="mt-3 grid grid-cols-2 gap-y-1 text-sm">
                <dt class="text-muted-foreground">Balance</dt>
                <dd class="text-right font-semibold">
                    <MoneyAmount :money="user.balance" />
                </dd>
                <dt class="text-muted-foreground">Available to spend</dt>
                <dd class="text-right">
                    <MoneyAmount :money="user.available_balance" />
                </dd>
            </dl>
            <p class="mt-3 text-[13px] text-muted-foreground">
                While a generation runs, its maximum possible price is set
                aside. When it finishes you pay the real price (never more than
                the maximum); if it fails, you pay nothing and the amount is
                available again.
            </p>
        </section>

        <section aria-labelledby="buy-title">
            <h2 id="buy-title" class="mb-3 text-sm font-semibold">
                Buy credits
            </h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <button
                    v-for="pack in packages"
                    :key="pack.value"
                    type="button"
                    :disabled="form.processing"
                    class="rounded-2xl border border-border bg-card p-5 text-left hover:border-input focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                    @click="buy(pack.value)"
                >
                    <span class="font-display text-2xl font-extrabold">{{
                        pack.amount.display
                    }}</span>
                    <span class="mt-1 block text-xs text-subtle-foreground"
                        >Test purchase, no payment</span
                    >
                </button>
            </div>
        </section>

        <section
            class="rounded-2xl border border-border bg-card p-5"
            aria-labelledby="ledger-title"
        >
            <h2 id="ledger-title" class="mb-2 text-sm font-semibold">
                Recent activity
            </h2>
            <p
                v-if="entries.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nothing yet.
            </p>
            <ul v-else class="divide-y divide-border text-[13px]">
                <li
                    v-for="entry in entries"
                    :key="entry.id"
                    class="flex items-center justify-between gap-4 py-2"
                >
                    <div class="min-w-0">
                        <p v-if="entry.type === 'purchase'">Credits added</p>
                        <Link
                            v-else-if="entry.generation"
                            :href="show(entry.generation.id)"
                            class="block truncate hover:underline"
                            >Generation: {{ entry.generation.prompt }}</Link
                        >
                        <p class="text-xs text-subtle-foreground">
                            {{ formatDateTime(entry.created_at) }}
                        </p>
                    </div>
                    <MoneyAmount :money="entry.amount" />
                </li>
            </ul>
        </section>
    </div>
</template>
