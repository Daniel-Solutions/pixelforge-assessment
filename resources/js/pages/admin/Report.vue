<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import FieldLabel from '@/components/studio/FieldLabel.vue';
import { report as reportRoute } from '@/routes/admin';
import type { Money } from '@/types';

type Totals = {
    succeeded: number;
    failed: number;
    charged: Money;
    provider_cost: Money;
    profit: Money;
};

type UserTotals = {
    user_id: number;
    name: string;
    email: string;
    totals: Totals;
};

const props = defineProps<{
    from: string;
    to: string;
    report: {
        days: {
            date: string;
            total: Totals;
            pixelforge_cost: Money | null;
            matches: boolean | null;
            difference: Money | null;
            users: UserTotals[];
        }[];
        users: UserTotals[];
        total: Totals;
        pixelforge_available: boolean;
    };
}>();

const filter = useForm({ from: props.from, to: props.to });

function applyFilter(): void {
    filter.get(reportRoute.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Report" />

    <div class="grid gap-6 px-4 py-5 lg:px-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-sm font-semibold">Report</h1>
                <p class="text-xs text-muted-foreground">
                    Per UTC day of completion ({{ from }} to {{ to }}), the same
                    day Pixelforge bills. Exact amounts, 9 decimal places.
                </p>
            </div>
            <form class="flex items-end gap-2" @submit.prevent="applyFilter">
                <div>
                    <FieldLabel for="from">From</FieldLabel>
                    <input
                        id="from"
                        v-model="filter.from"
                        type="date"
                        class="rounded-md border border-input bg-control px-2 py-1.5 text-sm shadow-control"
                    />
                </div>
                <div>
                    <FieldLabel for="to">To</FieldLabel>
                    <input
                        id="to"
                        v-model="filter.to"
                        type="date"
                        class="rounded-md border border-input bg-control px-2 py-1.5 text-sm shadow-control"
                    />
                </div>
                <button
                    type="submit"
                    class="h-9 rounded-md bg-primary px-3 text-xs font-semibold text-primary-foreground"
                >
                    Apply
                </button>
            </form>
        </div>
        <InputError :message="filter.errors.to ?? filter.errors.from" />

        <p
            v-if="!report.pixelforge_available"
            class="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm"
        >
            Pixelforge usage could not be loaded (check the logs; it also allows
            at most 92 days per request), so the "Pixelforge reported" column is
            empty.
        </p>

        <section
            class="overflow-x-auto rounded-2xl border border-border bg-card"
            aria-labelledby="by-day"
        >
            <h2 id="by-day" class="px-4 pt-4 text-sm font-semibold">By day</h2>
            <table class="w-full min-w-[860px] text-[13px]">
                <thead class="text-muted-foreground">
                    <tr class="border-b border-border">
                        <th class="px-4 py-2 text-left font-medium">
                            Day / user
                        </th>
                        <th class="px-2 py-2 text-right font-medium">
                            Succeeded
                        </th>
                        <th class="px-2 py-2 text-right font-medium">Failed</th>
                        <th class="px-2 py-2 text-right font-medium">
                            Credit spent
                        </th>
                        <th class="px-2 py-2 text-right font-medium">
                            Provider cost
                        </th>
                        <th class="px-2 py-2 text-right font-medium">
                            Pixelforge reported
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Profit</th>
                    </tr>
                </thead>
                <tbody class="font-mono tabular-nums">
                    <tr v-if="report.days.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-6 text-center font-sans text-muted-foreground"
                        >
                            No completed generations in this range.
                        </td>
                    </tr>
                    <template v-for="day in report.days" :key="day.date">
                        <tr
                            class="border-b border-border bg-sunken font-semibold"
                        >
                            <td class="px-4 py-2 font-sans">{{ day.date }}</td>
                            <td class="px-2 py-2 text-right">
                                {{ day.total.succeeded }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ day.total.failed }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ day.total.charged.display }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ day.total.provider_cost.display }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                <template v-if="day.pixelforge_cost">
                                    {{ day.pixelforge_cost.display }}
                                    <Check
                                        v-if="day.matches"
                                        class="ml-1 inline size-3.5 text-brand-ink"
                                        aria-label="Matches"
                                    />
                                    <span
                                        v-else
                                        class="ml-1 text-destructive-foreground"
                                        >Δ {{ day.difference?.exact }}</span
                                    >
                                </template>
                                <span v-else class="text-subtle-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ day.total.profit.display }}
                            </td>
                        </tr>
                        <tr
                            v-for="user in day.users"
                            :key="`${day.date}-${user.user_id}`"
                            class="border-b border-border"
                        >
                            <td class="py-2 pr-2 pl-8 font-sans">
                                {{ user.name }}
                                <span class="text-xs text-subtle-foreground">{{
                                    user.email
                                }}</span>
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ user.totals.succeeded }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ user.totals.failed }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ user.totals.charged.display }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ user.totals.provider_cost.display }}
                            </td>
                            <td class="px-2 py-2" />
                            <td class="px-4 py-2 text-right">
                                {{ user.totals.profit.display }}
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </section>

        <section
            class="overflow-x-auto rounded-2xl border border-border bg-card"
            aria-labelledby="by-user"
        >
            <h2 id="by-user" class="px-4 pt-4 text-sm font-semibold">
                By user
            </h2>
            <table class="w-full min-w-[720px] text-[13px]">
                <thead class="text-muted-foreground">
                    <tr class="border-b border-border">
                        <th class="px-4 py-2 text-left font-medium">User</th>
                        <th class="px-2 py-2 text-right font-medium">
                            Succeeded
                        </th>
                        <th class="px-2 py-2 text-right font-medium">Failed</th>
                        <th class="px-2 py-2 text-right font-medium">
                            Credit spent
                        </th>
                        <th class="px-2 py-2 text-right font-medium">
                            Provider cost
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Profit</th>
                    </tr>
                </thead>
                <tbody class="font-mono tabular-nums">
                    <tr
                        v-for="user in report.users"
                        :key="user.user_id"
                        class="border-b border-border"
                    >
                        <td class="px-4 py-2 font-sans">
                            {{ user.name }}
                            <span class="text-xs text-subtle-foreground">{{
                                user.email
                            }}</span>
                        </td>
                        <td class="px-2 py-2 text-right">
                            {{ user.totals.succeeded }}
                        </td>
                        <td class="px-2 py-2 text-right">
                            {{ user.totals.failed }}
                        </td>
                        <td class="px-2 py-2 text-right">
                            {{ user.totals.charged.display }}
                        </td>
                        <td class="px-2 py-2 text-right">
                            {{ user.totals.provider_cost.display }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ user.totals.profit.display }}
                        </td>
                    </tr>
                    <tr class="font-semibold">
                        <td class="px-4 py-2 font-sans">Total</td>
                        <td class="px-2 py-2 text-right">
                            {{ report.total.succeeded }}
                        </td>
                        <td class="px-2 py-2 text-right">
                            {{ report.total.failed }}
                        </td>
                        <td class="px-2 py-2 text-right">
                            {{ report.total.charged.display }}
                        </td>
                        <td class="px-2 py-2 text-right">
                            {{ report.total.provider_cost.display }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ report.total.profit.display }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
