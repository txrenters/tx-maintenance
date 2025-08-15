<script setup>
import { computed, ref, watch, nextTick } from "vue";
import { useDebounceFn } from "@vueuse/core";
import AppLayout from "@/Layouts/AppLayout.vue";
import {
    MessageSquare,
    Calendar,
    User,
    Phone,
    Clock,
    CheckCircle,
    Circle,
    Search,
    Filter,
    Download,
    Eye,
    Wrench,
    PhoneCall,
    Loader2,
} from "lucide-vue-next";
import Navigation from "./partials/Navigation.vue";
import { Card, CardContent, CardHeader } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import { Button } from "@/Components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/ui/table";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/Components/ui/dialog";
import { Head } from "@inertiajs/vue3";
import Pagination from "@/Components/Pagination.vue";
import PaginationResultRange from "@/Components/PaginationResultRange.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    conversations: Array,
});

const searchTerm = ref("");
const statusFilter = ref("all");
const workOrderFilter = ref("all");
const selectedConversation = ref(null);
const isViewModalOpen = ref(false);
const isLoading = ref(false);
const visibleItems = ref(50); // Virtual scrolling - show 50 items initially
const itemHeight = 64; // Approximate height of each table row in pixels

const openViewModal = async (conversation) => {
    isLoading.value = true;
    
    // Simulate lazy loading - in a real app, you might fetch additional data here
    await nextTick();
    
    selectedConversation.value = conversation;
    isViewModalOpen.value = true;
    isLoading.value = false;
};

const closeViewModal = () => {
    selectedConversation.value = null;
    isViewModalOpen.value = false;
};

// Debounced search to avoid excessive filtering
const debouncedSearch = useDebounceFn((term) => {
    searchTerm.value = term;
}, 300);

// Optimized filtering with early returns and reduced computations
const filteredConversations = computed(() => {
    if (!props.conversations?.data) return [];
    
    let filtered = props.conversations.data;
    const searchLower = searchTerm.value.toLowerCase();

    // Early return if no filters applied
    if (!searchLower && statusFilter.value === "all") {
        return filtered;
    }

    if (searchLower) {
        filtered = filtered.filter((conv) => {
            // Use optional chaining and early returns for better performance
            return (
                conv.message?.toLowerCase().includes(searchLower) ||
                conv.job_number?.toString().includes(searchLower) ||
                conv.client?.toLowerCase().includes(searchLower) ||
                conv.sender_number?.toLowerCase().includes(searchLower) ||
                conv.receiver_number?.toLowerCase().includes(searchLower)
            );
        });
    }

    if (statusFilter.value !== "all") {
        filtered = filtered.filter((conv) => {
            if (statusFilter.value === "read") return conv.is_read;
            return true;
        });
    }

    return filtered;
});

// Virtual scrolling - only show visible items
const visibleConversations = computed(() => {
    return filteredConversations.value.slice(0, visibleItems.value);
});

// Load more items when scrolling
const loadMoreItems = () => {
    if (visibleItems.value < filteredConversations.value.length) {
        visibleItems.value = Math.min(
            visibleItems.value + 25,
            filteredConversations.value.length
        );
    }
};

// Watch for search changes and reset visible items
watch(searchTerm, () => {
    visibleItems.value = 50;
});

// Memoize date formatting for better performance
const dateCache = new Map();
const formatDate = (dateString) => {
    if (dateCache.has(dateString)) {
        return dateCache.get(dateString);
    }
    
    const date = new Date(dateString);
    const options = { timeZone: "America/Chicago" };
    const formatted = date.toLocaleDateString("en-US", options) + " " + date.toLocaleTimeString("en-US", options);
    
    dateCache.set(dateString, formatted);
    return formatted;
};

const truncateMessage = (message, length = 100) => {
    if (message.length <= length) return message;
    return message.substring(0, length) + "...";
};
</script>

<template>
    <Head :title="title" />

    <div class="space-y-6">
        <Card>
            <CardHeader>
                <!-- Filters -->
                <div class="flex justify-between">
                    <div class="space-y-2">
                        <div class="relative">
                            <Search
                                class="absolute left-3 top-3 h-4 w-4 text-gray-400"
                            />
                            <Input
                                :model-value="searchTerm"
                                @input="debouncedSearch($event.target.value)"
                                type="search"
                                placeholder="Search messages..."
                                class="pl-10"
                            />
                        </div>
                    </div>
                    <Navigation />
                </div>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Job Number</TableHead>
                            <TableHead>Client</TableHead>
                            <TableHead>Sender</TableHead>
                            <TableHead>Receiver</TableHead>
                            <TableHead>Message</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody 
                        @scroll="loadMoreItems"
                        style="max-height: 600px; overflow-y: auto;"
                    >
                        <TableRow
                            v-for="conversation in visibleConversations"
                            :key="conversation.id"
                        >
                            <TableCell
                                >{{ conversation.job_number }}
                            </TableCell>
                            <TableCell>{{ conversation.client }} </TableCell>
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <Phone class="w-4 h-4 text-gray-400" />
                                    <span>{{
                                        conversation.sender_number || "Unknown"
                                    }}</span>
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <Phone class="w-4 h-4 text-gray-400" />
                                    <span>{{
                                        conversation.receiver_number ||
                                        "Unknown"
                                    }}</span>
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="max-w-md">
                                    <p class="text-sm">
                                        {{
                                            truncateMessage(
                                                conversation.message
                                            )
                                        }}
                                    </p>
                                </div>
                            </TableCell>
                            <TableCell>
                                <div
                                    class="flex items-center gap-2 text-sm text-gray-600"
                                >
                                    <Clock class="w-4 h-4" />
                                    {{ formatDate(conversation.created_at) }}
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="flex gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="gap-1"
                                        :disabled="isLoading"
                                        @click="openViewModal(conversation)"
                                    >
                                        <Loader2 v-if="isLoading" class="w-4 h-4 animate-spin" />
                                        <Eye v-else class="w-4 h-4" />
                                        {{ isLoading ? 'Loading...' : 'View' }}
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div
                    v-if="filteredConversations.length === 0"
                    class="text-center py-8"
                >
                    <MessageSquare
                        class="w-12 h-12 text-gray-400 mx-auto mb-4"
                    />
                    <p class="text-gray-500">
                        No conversations found matching your criteria
                    </p>
                </div>
                
                <!-- Load more indicator -->
                <div 
                    v-if="visibleItems < filteredConversations.length"
                    class="text-center py-4 border-t"
                >
                    <Button 
                        variant="outline" 
                        size="sm"
                        @click="loadMoreItems"
                    >
                        Load More ({{ filteredConversations.length - visibleItems }} remaining)
                    </Button>
                </div>
                <div class="flex justify-between">
                    <PaginationResultRange :data="conversations" />
                    <Pagination :pagination="conversations.links" />
                </div>
            </CardContent>
        </Card>

        <!-- View Modal -->
        <Dialog v-model:open="isViewModalOpen">
            <DialogContent class="max-w-2xl">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <MessageSquare class="w-5 h-5" />
                        Message Details
                    </DialogTitle>
                    <DialogDescription>
                        Full conversation details and metadata
                    </DialogDescription>
                </DialogHeader>

                <div v-if="selectedConversation" class="space-y-6">
                    <!-- Message Content -->
                    <div class="space-y-3">
                        <h3 class="font-semibold text-lg">Message</h3>
                        <div class="border p-4 rounded-lg">
                            <p class="text-sm whitespace-pre-wrap">
                                {{ selectedConversation.message }}
                            </p>
                        </div>
                    </div>

                    <!-- Metadata -->
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="space-y-3">
                            <h4 class="font-medium">Sender Information</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex items-center gap-2">
                                    <Phone class="w-4 h-4 text-gray-500" />
                                    <span class="font-medium">Phone:</span>
                                    <span>{{
                                        selectedConversation.sender_number ||
                                        "N/A"
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <h4 class="font-medium">Receiver Information</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex items-center gap-2">
                                    <Phone class="w-4 h-4 text-gray-500" />
                                    <span class="font-medium">Phone:</span>
                                    <span>{{
                                        selectedConversation.receiver_number ||
                                        "N/A"
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <h4 class="font-medium">Message Details</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex gap-1 flex-col">
                                    <div class="flex gap-2 items-center">
                                        <Clock class="w-4 h-4" />
                                        <span class="font-medium">Sent:</span>
                                    </div>

                                    <span>{{
                                        formatDate(
                                            selectedConversation.created_at
                                        )
                                    }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <Button variant="outline" @click="closeViewModal">
                        Close
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
