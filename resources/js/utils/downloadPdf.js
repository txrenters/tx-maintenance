import axios from "axios";

const fileNameFrom = (disposition, fallback) =>
    /filename="([^"]+)"/.exec(disposition ?? "")?.[1] ?? fallback;

/**
 * POST to a PDF endpoint and save the response under the server's file name.
 * Throws on failure so the caller can show its own toast.
 */
export async function downloadPdf(url, payload = {}, fallbackName = "Conversation.pdf") {
    const response = await axios.post(url, payload, { responseType: "blob" });

    const objectUrl = URL.createObjectURL(response.data);
    const link = document.createElement("a");
    link.href = objectUrl;
    link.download = fileNameFrom(response.headers["content-disposition"], fallbackName);
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}
