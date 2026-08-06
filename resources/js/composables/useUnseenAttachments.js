import { computed, ref, unref } from "vue";

/**
 * Messenger-style number badge for the Attachments tab.
 *
 * Holds the count of files that arrived on a work order (tenant photos,
 * portal or vendor uploads) that no staff member has opened the tab for yet,
 * and decorates the tab-button config with a red `count` chip while it's
 * non-zero. The pages set the count from `unseen_attachments_count` on the
 * work_orders.data payload and zero it after fetching the Attachments tab —
 * the server stamps everything viewed on that same fetch.
 *
 * @param {Array|import("vue").Ref<Array>} buttons tab-button config for TabSwitcher
 */
export function useUnseenAttachments(buttons) {
    const unseenAttachments = ref(0);

    const buttonsWithAttachmentBadge = computed(() => {
        const list = unref(buttons);

        if (!unseenAttachments.value) {
            return list;
        }

        return list.map((button) =>
            button.name === "attachments"
                ? {
                      ...button,
                      count: unseenAttachments.value,
                      countVariant: "alert",
                  }
                : button
        );
    });

    return { unseenAttachments, buttonsWithAttachmentBadge };
}