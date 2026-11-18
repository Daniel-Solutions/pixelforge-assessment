<script setup lang="ts">
defineOptions({ inheritAttrs: false });
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        aria-hidden="true"
        v-bind="$attrs"
    >
        <rect x="2" y="2" width="9" height="9" rx="2" fill="currentColor" />
        <rect
            x="13"
            y="2"
            width="9"
            height="9"
            rx="2"
            fill="currentColor"
            opacity="0.35"
        />
        <rect
            x="2"
            y="13"
            width="9"
            height="9"
            rx="2"
            fill="currentColor"
            opacity="0.35"
        />
        <rect x="13" y="13" width="9" height="9" rx="2" class="fill-brand" />
    </svg>
</template>
