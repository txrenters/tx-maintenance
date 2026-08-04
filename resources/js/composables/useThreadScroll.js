import { nextTick, ref } from "vue";

/**
 * Keeps a conversation pinned to its newest message.
 *
 * The messages render inside reka-ui's ScrollArea viewport, so the element that
 * actually scrolls is neither the ScrollArea wrapper nor the anchor's immediate
 * parent — it is the viewport in between. Attach `bottomAnchor` to an empty
 * element placed after the last message inside the ScrollArea.
 */
export function useThreadScroll() {
    const bottomAnchor = ref(null);

    const viewport = () =>
        bottomAnchor.value?.closest("[data-reka-scroll-area-viewport]") ?? null;

    /** Within this many pixels of the end counts as "reading the latest". */
    const NEAR_BOTTOM_PX = 120;

    const isNearBottom = () => {
        const el = viewport();
        if (!el) return true;

        return el.scrollHeight - el.scrollTop - el.clientHeight < NEAR_BOTTOM_PX;
    };

    /**
     * Scroll to the newest message. Unforced calls (a background refetch) are
     * ignored while the coordinator is scrolled up reading history.
     */
    const scrollToBottom = (force = false) => {
        if (!force && !isNearBottom()) return;

        nextTick(() => {
            bottomAnchor.value?.scrollIntoView({ block: "end" });
        });
    };

    return { bottomAnchor, isNearBottom, scrollToBottom };
}
