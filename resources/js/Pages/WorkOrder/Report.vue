<script setup>
import { ref, computed } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { DateTime } from "luxon";
import MessageCard2 from "@/Components/MessageCard2.vue";
import FilesInvoice from "@/Pages/WorkOrder/Partials/FilesInvoice.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    work_order: Object,
});

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    try {
        if (typeof date === "string") {
            if (date.includes("T")) {
                // Handle ISO format (2025-03-06T17:41:20.000000Z)
                parsedDate = DateTime.fromISO(date, { zone: "utc" });
            } else if (date.includes("-")) {
                // Handle date string (2025-03-06 or 2025-03-06 23:10:06)
                parsedDate = DateTime.fromFormat(
                    date.split(" ")[0],
                    "yyyy-MM-dd",
                    {
                        zone: "utc",
                    }
                );
            } else {
                return "Invalid Date Format";
            }
        } else if (date instanceof Date) {
            parsedDate = DateTime.fromJSDate(date);
        } else {
            return "Invalid Date";
        }

        if (!parsedDate.isValid) return "Invalid Date";

        // Format as "Sat, March 29, 2025"
        return parsedDate.toFormat("EEE, MMMM d, yyyy");
    } catch (error) {
        console.error("Date formatting error:", error);
        return "Invalid Date";
    }
};

const totalCostEstimate = computed(() => {
    return props.work_order?.vendors?.reduce((total, vendor) => {
        return total + parseFloat(vendor.pivot?.cost_estimate || 0);
    }, 0);
});

const totalTimeEstimate = computed(() => {
    return (props.work_order?.vendors || []).reduce((total, vendor) => {
        return total + parseInt(vendor.pivot?.time_estimate || 0);
    }, 0);
});
</script>
<template>
    <Head :title="title" />
    <div class="flex justify-end">
        <Button>Download</Button>
    </div>
    <Card>
        <CardHeader
            class="text-center flex flex-col justify-center sm:flex-row sm:justify-between gap-4"
        >
            <div class="flex justify-center h-20">
                <img src="/logo-ct.png" />
            </div>
            <div class="flex flex-col justify-end gap-2 text-right">
                <CardTitle class="uppercase">{{ title }}</CardTitle>
                <CardTitle class="uppercase text-2xl"
                    >Work Order No: #{{ work_order.work_order_no }}</CardTitle
                >
                <p class="uppercase font-semibold">
                    Complete Date: {{ formatDate(work_order.completed_date) }}
                </p>
            </div>
        </CardHeader>
        <CardContent>
            <div class="grid grid-cols-2 gap-3 p-4 border mb-4">
                <div>
                    <Label for="message">Managed by:</Label>
                    <p>
                        {{ work_order.managed_by?.first_name }}
                        {{ work_order.managed_by?.last_name }}
                    </p>
                </div>
                <div>
                    <Label for="message">Requested by:</Label>
                    <p>
                        {{ work_order.requested_by?.first_name }}
                        {{ work_order.requested_by?.last_name }}
                    </p>
                </div>
                <div>
                    <Label for="message">Location:</Label>
                    <p>{{ work_order.location }}</p>
                </div>
                <div>
                    <Label for="message">Category:</Label>
                    <p>{{ work_order.category }}</p>
                </div>
                <div>
                    <Label for="message">Type:</Label>
                    <p>{{ work_order.type }}</p>
                </div>
                <div>
                    <Label for="message">Authorized to enter:</Label>
                    <p>{{ work_order.authorized_to_enter }}</p>
                </div>
                <div>
                    <Label for="message">Source:</Label>
                    <p>{{ work_order.source }}</p>
                </div>
                <div>
                    <Label for="message">Total Cost:</Label>
                    <p>{{ totalCostEstimate }}</p>
                </div>
                <div>
                    <Label for="message">Total Hour Worked:</Label>
                    <p>{{ totalTimeEstimate }}</p>
                </div>
                <div>
                    <Label for="message">Zone:</Label>
                    <p>{{ work_order.zone }}</p>
                </div>
                <div>
                    <Label for="message">Management Plan:</Label>
                    <p>{{ work_order.management_plan }}</p>
                </div>
                <div>
                    <Label for="message">Additional Work Needed:</Label>
                    <p>{{ work_order.additional_work_needed_reschedule }}</p>
                </div>
                <div>
                    <Label for="message">Closing Comments:</Label>
                    <p>{{ work_order.closing_comments }}</p>
                </div>
                <div>
                    <Label for="message">Description:</Label>
                    <p>{{ work_order.decription }}</p>
                </div>
            </div>
            <div class="p-4 border mb-4">
                <p>Task Details</p>
                <div
                    class="mt-4"
                    v-for="task in work_order.tasks"
                    :key="task.id"
                >
                    <div class="border p-2 flex flex-col mb-2">
                        <small>Status: {{ task.status }}</small>
                        <p>{{ task.description }}</p>
                        <small
                            >Assigned to: {{ task.assigned_user.name }}</small
                        >
                        <small>{{ formatDate(task.updated_at) }}</small>
                    </div>
                </div>
                <p
                    class="text-xs text-gray-500"
                    v-if="work_order.tasks.length === 0"
                >
                    No tasks available for this work order.
                </p>
            </div>

            <div class="p-4 border mb-4">
                <p>Notes</p>
                <div
                    class="mt-4"
                    v-for="note in work_order.notes"
                    :key="note.id"
                >
                    <div class="border p-2 flex flex-col mb-2">
                        <small>{{ note.subject }}</small>
                        <p>{{ note.body }}</p>
                    </div>
                </div>
                <p
                    class="text-xs text-gray-500"
                    v-if="work_order.notes.length === 0"
                >
                    No notes available for this work order.
                </p>
            </div>

            <div class="p-4 border mb-4">
                <p>Service Schedules</p>
                <div
                    class="mt-4"
                    v-for="service_schedule in work_order.service_schedules"
                    :key="service_schedule.id"
                >
                    <div class="border p-2 flex flex-col mb-2">
                        <small>Status: {{ service_schedule.status }}</small>
                        <p>{{ service_schedule.description }}</p>
                        <small>{{
                            formatDate(service_schedule.scheduled_date)
                        }}</small>
                    </div>
                </div>
                <p
                    class="text-xs text-gray-500"
                    v-if="work_order.service_schedules.length === 0"
                >
                    No service schedules available for this work order.
                </p>
            </div>

            <div class="p-4 border mb-4">
                <p>Invoices</p>
                <div class="mb-3">
                    <FilesInvoice :files="work_order.invoices" />
                </div>
                <p
                    class="text-xs text-gray-500"
                    v-if="work_order.invoices.length === 0"
                >
                    No invoices available for this work order.
                </p>
            </div>

            <div class="p-4 border mb-4">
                <p class="mb-4">Conversations</p>
                <div class="mb-4 p-2 border">
                    <p class="mb-2 font-bold">WOC and Vendor</p>
                    <MessageCard2 :messages="work_order.vendor_conversation" />
                    <p
                        class="text-xs text-gray-500"
                        v-if="work_order.vendor_conversation.length === 0"
                    >
                        No WOC and Vendor conversations available for this work
                        order.
                    </p>
                </div>
                <div class="mb-4 p-2 border">
                    <p class="mb-2 font-bold">WOC and Owner</p>
                    <MessageCard2 :messages="work_order.owner_conversation" />
                    <p
                        class="text-xs text-gray-500"
                        v-if="work_order.owner_conversation.length === 0"
                    >
                        No WOC and Owner conversations available for this work
                        order.
                    </p>
                </div>
                <div class="mb-4 p-2 border">
                    <p class="mb-2 font-bold">WOC and Tenant</p>
                    <MessageCard2 :messages="work_order.tenant_conversation" />
                    <p
                        class="text-xs text-gray-500"
                        v-if="work_order.tenant_conversation.length === 0"
                    >
                        No WOC and Tenant conversations available for this work
                        order.
                    </p>
                </div>
                <div class="p-2 border">
                    <p class="mb-2 font-bold">Vendor and Tenant</p>
                    <MessageCard2
                        :messages="work_order.vendor_tenant_conversation"
                    />
                    <p
                        class="text-xs text-gray-500"
                        v-if="
                            work_order.vendor_tenant_conversation.length === 0
                        "
                    >
                        No Vendor and Tenant conversations available for this
                        work order.
                    </p>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
