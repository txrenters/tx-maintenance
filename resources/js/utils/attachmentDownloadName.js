// Filename the browser saves an attachment under. Staff name their photos
// ("Kitchen leak - before"); the storage path is a random hash, so the title
// wins and the original extension follows it. Characters Windows refuses in a
// filename are dropped so the save never fails silently.

const MIME_EXTENSIONS = {
    "image/jpeg": "jpg",
    "image/png": "png",
    "image/gif": "gif",
    "image/webp": "webp",
    "image/heic": "heic",
    "application/pdf": "pdf",
};

const ILLEGAL = /[\\/:*?"<>|\x00-\x1f]/g;

export function attachmentDownloadName(title, path, mime) {
    const source = String(path || "").split(/[?#]/)[0];
    const basename = source.split("/").pop() || "";
    const dot = basename.lastIndexOf(".");

    let extension = dot > 0 ? basename.slice(dot + 1).toLowerCase() : "";
    if (!extension) {
        extension = MIME_EXTENSIONS[String(mime || "").toLowerCase()] || "";
    }

    let base = String(title || "")
        .replace(ILLEGAL, "")
        .trim()
        .replace(/\.+$/, "");
    if (!base) {
        base = (dot > 0 ? basename.slice(0, dot) : basename) || "attachment";
    }

    if (!extension || base.toLowerCase().endsWith(`.${extension}`)) {
        return base;
    }

    return `${base}.${extension}`;
}
