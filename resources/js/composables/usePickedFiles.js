import { ref } from "vue";

const isImageFile = (file) =>
    file.type.startsWith("image/") || /\.(jpe?g|png|gif|webp|heic)$/i.test(file.name);

/**
 * One file picker's worth of state: the files themselves, a preview per file,
 * and the hidden <input> backing it. Shared by every picker on the tenant
 * portal so the de-duplication and the object-URL cleanup live in one place
 * rather than being copied per tab.
 */
export function usePickedFiles() {
    const files = ref([]);
    const previews = ref([]);
    const input = ref(null);

    const onPick = (e) => {
        Array.from(e.target.files || []).forEach((file) => {
            const isDuplicate = files.value.some(
                (existing) => existing.name === file.name && existing.size === file.size,
            );
            if (isDuplicate) {
                return;
            }

            files.value.push(file);

            const image = isImageFile(file);
            previews.value.push({
                name: file.name,
                isImage: image,
                url: image ? URL.createObjectURL(file) : null,
            });
        });

        // Reset so re-picking the same file still fires @change.
        e.target.value = "";
    };

    const remove = (index) => {
        const [removed] = previews.value.splice(index, 1);
        if (removed?.url) {
            URL.revokeObjectURL(removed.url);
        }
        files.value.splice(index, 1);
    };

    const reset = () => {
        previews.value.forEach((preview) => {
            if (preview.url) {
                URL.revokeObjectURL(preview.url);
            }
        });
        files.value = [];
        previews.value = [];
        if (input.value) {
            input.value.value = "";
        }
    };

    return { files, previews, input, onPick, remove, reset };
}
