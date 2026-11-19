import { router } from "@inertiajs/vue3";

/**
 * Upload many files as a series of small requests instead of one big one.
 *
 * The server has three ceilings a browser cannot see: nginx refuses a request
 * body over 200 MB with a bare "413 Request Entity Too Large" page, PHP keeps
 * only the first 20 files of a request (max_file_uploads) and drops the rest
 * without a word, and PHP refuses any single file over 50 MB
 * (upload_max_filesize). A make-ready set of 80 phone photos trips all three.
 *
 * Nothing here touches the photos themselves: every byte the user picked is
 * sent as picked. Only the grouping changes.
 */

/** PHP max_file_uploads in docker/php/php.ini. */
export const MAX_FILES_PER_REQUEST = 20;

/** Under the 200M client_max_body_size / post_max_size, with room for headers. */
export const MAX_BYTES_PER_REQUEST = 150 * 1024 * 1024;

/** PHP upload_max_filesize; the server validates the same 51200 KB. */
export const MAX_FILE_BYTES = 50 * 1024 * 1024;

export const TOO_LARGE_MESSAGE =
    "That upload was too large for the server. Try fewer photos at a time.";

const ARCHIVE_RE = /\.(zip|rar|7z|tar|gz|tgz)$/i;

export const formatBytes = (bytes) => {
    const mb = bytes / (1024 * 1024);
    if (mb >= 10) {
        return `${Math.round(mb)} MB`;
    }
    if (mb >= 1) {
        return `${mb.toFixed(1)} MB`;
    }
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
};

/**
 * Why a file can never be accepted, decided before anything is sent, or null
 * when it may go. Phone pickers ignore the <input accept> list, so a zip or
 * a video-sized file can still arrive here.
 */
export const rejectionReason = (file) => {
    if (ARCHIVE_RE.test(file.name)) {
        return `${file.name} is a ZIP file, which the portal cannot take. Unzip it and pick the photos inside.`;
    }
    if (file.size > MAX_FILE_BYTES) {
        return `${file.name} is ${formatBytes(file.size)}. Each file must be ${formatBytes(MAX_FILE_BYTES)} or smaller.`;
    }
    return null;
};

/**
 * Group files in picked order so no request carries more than maxFiles files
 * or more than maxBytes bytes. A single file over maxBytes still travels alone
 * (the per-file check above is what refuses it).
 *
 * @returns {File[][]}
 */
export const splitIntoBatches = (
    files,
    { maxFiles = MAX_FILES_PER_REQUEST, maxBytes = MAX_BYTES_PER_REQUEST } = {},
) => {
    const batches = [];
    let current = [];
    let currentBytes = 0;

    for (const file of files) {
        const wouldOverflow =
            current.length > 0 &&
            (current.length >= maxFiles || currentBytes + file.size > maxBytes);
        if (wouldOverflow) {
            batches.push(current);
            current = [];
            currentBytes = 0;
        }
        current.push(file);
        currentBytes += file.size;
    }
    if (current.length > 0) {
        batches.push(current);
    }

    return batches;
};

/**
 * One Inertia POST, resolved when it finishes however it ended. A 413 is not
 * an Inertia response, so the router would otherwise show nginx's error page
 * in a modal; it is turned into a plain message instead.
 *
 * @returns {Promise<{ ok: boolean, error: string|null }>}
 */
const postOnce = (url, data, { preserveScroll }) =>
    new Promise((resolve) => {
        const outcome = { ok: false, error: null };

        const stopListening = router.on("invalid", (event) => {
            const status = event.detail?.response?.status;
            if (status === 413) {
                event.preventDefault();
                outcome.error = TOO_LARGE_MESSAGE;
                return;
            }
            outcome.error =
                "The server could not take that upload. Please try again.";
        });

        router.post(url, data, {
            preserveScroll,
            preserveState: true,
            forceFormData: true,
            onSuccess: () => {
                outcome.ok = true;
            },
            onError: (errors) => {
                outcome.error =
                    Object.values(errors || {})[0] ||
                    "Please check the files and try again.";
            },
            onFinish: () => {
                stopListening();
                resolve(outcome);
            },
        });
    });

/**
 * Send files in batches, one request after another, reporting progress after
 * each. Stops at the first failed batch: files already sent stay on the work
 * order, and everything from the failed batch on is handed back so the caller
 * can keep it in the picker.
 *
 * @param {object} options
 * @param {string} options.url
 * @param {File[]} options.files
 * @param {(batch: File[]) => FormData|object} options.buildData
 * @param {(progress: { done: number, total: number, percent: number }) => void} [options.onProgress]
 * @param {boolean} [options.preserveScroll]
 * @returns {Promise<{ uploaded: File[], remaining: File[], error: string|null }>}
 */
export const uploadInBatches = async ({
    url,
    files,
    buildData,
    onProgress = () => {},
    preserveScroll = true,
}) => {
    const batches = splitIntoBatches(files);
    const uploaded = [];
    const remaining = [];
    let error = null;

    onProgress({ done: 0, total: files.length, percent: 0 });

    for (const batch of batches) {
        if (error) {
            remaining.push(...batch);
            continue;
        }

        const result = await postOnce(url, buildData(batch), { preserveScroll });
        if (!result.ok) {
            error = result.error || "Please check the files and try again.";
            remaining.push(...batch);
            continue;
        }

        uploaded.push(...batch);
        onProgress({
            done: uploaded.length,
            total: files.length,
            percent: Math.round((uploaded.length / files.length) * 100),
        });
    }

    return { uploaded, remaining, error };
};
