<script setup>
/**
 * A summary report: a page of ruled lines with a pencil writing on it.
 *
 * Hand-drawn rather than pulled from lucide so it matches the reference icon,
 * but kept to lucide's conventions — 24x24 box, currentColor, 2px round strokes
 * — so it sits correctly beside the other icons in the app.
 */
defineProps({
    // Matches lucide's API so it can be swapped in without changing call sites.
    size: { type: [Number, String], default: 24 },
    strokeWidth: { type: [Number, String], default: 2 },
});
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        :stroke-width="strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <!-- Page: left, top and bottom edges. The bottom-right is left open so
             the pencil reads as resting on top of the page. -->
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h4" />

        <!-- Folded top-right corner: the folded edge, then the flap. -->
        <path d="M14 2l6 6v2" />
        <path d="M14 2v6h6" />

        <!-- The written lines of the summary. -->
        <path d="M8 11h5" />
        <path d="M8 14.5h5" />
        <path d="M8 18h3" />

        <!-- Pencil lying across the lower-right, drawn the way lucide draws its
             pen: one large arc for the butt, a taper to the nib. -->
        <path d="M19 12a1.6 1.6 0 1 1 2.2 2.2l-6.6 6.6-2.9.7.7-2.9Z" />
        <path d="M17.4 13.7l2.2 2.2" />
    </svg>
</template>
