<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, History, Menu, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { report } from '@/routes/admin';
import { create, index } from '@/routes/generations';
import type { BreadcrumbItem, NavItem } from '@/types';

defineProps<{ breadcrumbs?: BreadcrumbItem[] }>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const { isCurrentUrl } = useCurrentUrl();

const navItems = computed<NavItem[]>(() => [
    { title: 'Generate', href: create(), icon: Sparkles },
    { title: 'History', href: index(), icon: History },
    ...(user.value.is_admin
        ? [{ title: 'Report', href: report(), icon: ChartColumn }]
        : []),
]);
</script>

<template>
    <header class="border-b border-border bg-background">
        <div class="flex h-16 items-center gap-6 px-4 lg:px-6">
            <Sheet>
                <SheetTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="lg:hidden"
                        aria-label="Open navigation"
                    >
                        <Menu class="size-5" />
                    </Button>
                </SheetTrigger>
                <SheetContent side="left" class="w-72 p-6">
                    <SheetTitle class="mb-4"><AppLogo /></SheetTitle>
                    <nav class="grid gap-1" aria-label="Main">
                        <Link
                            v-for="item in navItems"
                            :key="item.title"
                            :href="item.href"
                            class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-semibold hover:bg-accent"
                            :class="isCurrentUrl(item.href) ? 'bg-accent' : ''"
                        >
                            <component
                                :is="item.icon"
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ item.title }}
                        </Link>
                    </nav>
                </SheetContent>
            </Sheet>

            <Link :href="create()" aria-label="Pixelforge Studio home">
                <AppLogo />
            </Link>

            <nav
                class="hidden h-full items-stretch gap-1 lg:flex"
                aria-label="Main"
            >
                <Link
                    v-for="item in navItems"
                    :key="item.title"
                    :href="item.href"
                    class="relative flex items-center px-3 text-sm font-semibold text-foreground hover:text-foreground/80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-current="isCurrentUrl(item.href) ? 'page' : undefined"
                >
                    {{ item.title }}
                    <span
                        v-if="isCurrentUrl(item.href)"
                        class="absolute inset-x-0 bottom-0 h-0.5 translate-y-px bg-brand-ink"
                        aria-hidden="true"
                    />
                </Link>
            </nav>

            <div class="ml-auto flex items-center gap-2">
                <slot name="right" />
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="h-8 rounded-md bg-primary px-2.5 text-xs font-semibold text-primary-foreground shadow-control focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {{ user.name }}
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </header>
</template>
