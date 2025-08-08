<script setup>
import { ref, onMounted } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/Components/ui/tabs";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Alert, AlertDescription } from "@/Components/ui/alert";
import {
    Home,
    ClipboardList,
    MessageSquare,
    Calendar,
    DollarSign,
    CheckSquare,
    Settings,
    HelpCircle,
    Smartphone,
    AlertCircle,
    BookOpen,
    Lightbulb,
    Info,
    CheckCircle2,
    XCircle,
    Clock,
    ArrowRight,
    Mail,
    Phone as PhoneIcon,
    Camera,
    FileText,
    Star,
    User,
    LogIn,
    Key,
    Bell,
    PlayCircle,
    Youtube,
} from "lucide-vue-next";

defineOptions({ layout: AppLayout });

defineProps({
    title: String,
});

const activeTab = ref("getting-started");

const tabButtons = [
    { value: "getting-started", label: "Getting Started", icon: Home },
    { value: "dashboard", label: "Dashboard", icon: Settings },
    { value: "work-orders", label: "Work Orders", icon: ClipboardList },
    { value: "communication", label: "Communication", icon: MessageSquare },
    { value: "tasks", label: "Tasks", icon: CheckSquare },
    { value: "invoices", label: "Invoices", icon: DollarSign },
    { value: "calendar", label: "Calendar", icon: Calendar },
    { value: "mobile", label: "Mobile", icon: Smartphone },
    { value: "best-practices", label: "Best Practices", icon: Lightbulb },
    { value: "troubleshooting", label: "Troubleshooting", icon: HelpCircle },
    { value: "video-tutorials", label: "Video Tutorials", icon: FileText },
];

// No need to load external scripts for YouTube embeds
</script>

<template>
    <Head :title="'Vendor Guide'" />

    <div>
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight">Vendor Guide</h1>
            <p class="text-muted-foreground mt-2">
                Everything you need to know about using the TX Work Order system
                as a vendor
            </p>
        </div>

        <!-- Main Content -->
        <Tabs v-model:model-value="activeTab" class="space-y-4">
            <ScrollArea class="w-full whitespace-nowrap rounded-md border">
                <TabsList class="w-full justify-start">
                    <TabsTrigger value="getting-started">
                        <Home class="w-4 h-4 mr-2" />
                        Getting Started
                    </TabsTrigger>
                    <TabsTrigger value="dashboard">
                        <Settings class="w-4 h-4 mr-2" />
                        Dashboard
                    </TabsTrigger>
                    <TabsTrigger value="work-orders">
                        <ClipboardList class="w-4 h-4 mr-2" />
                        Work Orders
                    </TabsTrigger>
                    <TabsTrigger value="communication">
                        <MessageSquare class="w-4 h-4 mr-2" />
                        Communication
                    </TabsTrigger>
                    <TabsTrigger value="tasks">
                        <CheckSquare class="w-4 h-4 mr-2" />
                        Tasks
                    </TabsTrigger>
                    <TabsTrigger value="invoices">
                        <DollarSign class="w-4 h-4 mr-2" />
                        Invoices
                    </TabsTrigger>
                    <TabsTrigger value="calendar">
                        <Calendar class="w-4 h-4 mr-2" />
                        Calendar
                    </TabsTrigger>
                    <TabsTrigger value="mobile">
                        <Smartphone class="w-4 h-4 mr-2" />
                        Mobile
                    </TabsTrigger>
                    <TabsTrigger value="best-practices">
                        <AlertCircle class="w-4 h-4 mr-2" />
                        Best Practices
                    </TabsTrigger>
                    <TabsTrigger value="troubleshooting">
                        <HelpCircle class="w-4 h-4 mr-2" />
                        Troubleshooting
                    </TabsTrigger>
                    <TabsTrigger value="video-tutorials">
                        <FileText class="w-4 h-4 mr-2" />
                        Video Tutorials
                    </TabsTrigger>
                </TabsList>
            </ScrollArea>

            <!-- Getting Started -->
            <TabsContent value="getting-started" class="space-y-6">
                <div class="grid gap-6">
                    <!-- Login Process -->
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-2">
                                <LogIn class="w-5 h-5 text-primary" />
                                <CardTitle>Login Process</CardTitle>
                            </div>
                            <CardDescription
                                >How to access your vendor
                                account</CardDescription
                            >
                        </CardHeader>
                        <CardContent>
                            <ol class="space-y-3">
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >1</span
                                    >
                                    <span
                                        >Navigate to the TX Work Order login
                                        page</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >2</span
                                    >
                                    <span
                                        >Enter your registered email
                                        address</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >3</span
                                    >
                                    <span>Enter your password</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >4</span
                                    >
                                    <span
                                        >Click "Sign In" to access your
                                        dashboard</span
                                    >
                                </li>
                            </ol>
                            <Alert class="mt-4">
                                <Info class="w-4 h-4" />
                                <AlertDescription>
                                    If you forget your password, click "Forgot
                                    your password?" on the login page to reset
                                    it.
                                </AlertDescription>
                            </Alert>
                        </CardContent>
                    </Card>

                    <!-- First Time Setup -->
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-2">
                                <Settings class="w-5 h-5 text-primary" />
                                <CardTitle>First Time Setup</CardTitle>
                            </div>
                            <CardDescription
                                >Essential steps for new
                                vendors</CardDescription
                            >
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <User
                                        class="w-5 h-5 text-muted-foreground mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Update Profile Information
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Add your contact details and
                                            business information
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <Bell
                                        class="w-5 h-5 text-muted-foreground mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Configure Notifications
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Set preferences for email and SMS
                                            alerts
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <ClipboardList
                                        class="w-5 h-5 text-muted-foreground mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Review Assigned Work Orders
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Check any pending assignments
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <Home
                                        class="w-5 h-5 text-muted-foreground mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Explore Dashboard
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Familiarize yourself with the
                                            interface
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Key Terminology -->
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-2">
                                <BookOpen class="w-5 h-5 text-primary" />
                                <CardTitle>Key Terminology</CardTitle>
                            </div>
                            <CardDescription
                                >Important terms you'll
                                encounter</CardDescription
                            >
                        </CardHeader>
                        <CardContent>
                            <div class="grid gap-4">
                                <div class="border-l-4 border-primary pl-4">
                                    <p class="font-semibold">Work Order</p>
                                    <p class="text-sm text-muted-foreground">
                                        A service request that needs to be
                                        completed at a property
                                    </p>
                                </div>
                                <div class="border-l-4 border-primary pl-4">
                                    <p class="font-semibold">
                                        WOC (Work Order Coordinator)
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        The person who manages and assigns work
                                        orders to vendors
                                    </p>
                                </div>
                                <div class="border-l-4 border-primary pl-4">
                                    <p class="font-semibold">Task</p>
                                    <p class="text-sm text-muted-foreground">
                                        Specific items to complete within a work
                                        order
                                    </p>
                                </div>
                                <div class="border-l-4 border-primary pl-4">
                                    <p class="font-semibold">Service Status</p>
                                    <p class="text-sm text-muted-foreground">
                                        The current stage of work order
                                        completion
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </TabsContent>

            <!-- Dashboard -->
            <TabsContent value="dashboard" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Dashboard Overview</CardTitle>
                        <CardDescription
                            >Understanding your vendor dashboard metrics and
                            navigation</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Metrics Grid -->
                        <div>
                            <h3 class="text-lg font-semibold mb-4">
                                Key Metrics
                            </h3>
                            <div
                                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4"
                            >
                                <div class="p-4 border rounded-lg">
                                    <div class="flex items-center gap-2 mb-2">
                                        <ClipboardList
                                            class="w-4 h-4 text-primary"
                                        />
                                        <p class="font-semibold">
                                            Total Work Orders
                                        </p>
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        All work orders assigned to you
                                    </p>
                                </div>
                                <div class="p-4 border rounded-lg">
                                    <div class="flex items-center gap-2 mb-2">
                                        <CheckSquare
                                            class="w-4 h-4 text-primary"
                                        />
                                        <p class="font-semibold">Tasks</p>
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        Individual tasks across all orders
                                    </p>
                                </div>
                                <div class="p-4 border rounded-lg">
                                    <div class="flex items-center gap-2 mb-2">
                                        <DollarSign
                                            class="w-4 h-4 text-primary"
                                        />
                                        <p class="font-semibold">Invoices</p>
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        Billing and payment status
                                    </p>
                                </div>
                                <div class="p-4 border rounded-lg">
                                    <div class="flex items-center gap-2 mb-2">
                                        <Star class="w-4 h-4 text-primary" />
                                        <p class="font-semibold">Performance</p>
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        Your vendor statistics
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Navigation Guide -->
                        <div>
                            <h3 class="text-lg font-semibold mb-4">
                                Navigation Menu
                            </h3>
                            <div class="space-y-3">
                                <div
                                    class="flex items-center gap-3 p-3 border rounded-lg"
                                >
                                    <Home class="w-5 h-5 text-primary" />
                                    <div>
                                        <p class="font-medium">Dashboard</p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Overview of your work and statistics
                                        </p>
                                    </div>
                                </div>
                                <div
                                    class="flex items-center gap-3 p-3 border rounded-lg"
                                >
                                    <ClipboardList
                                        class="w-5 h-5 text-primary"
                                    />
                                    <div>
                                        <p class="font-medium">Work Orders</p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            View and manage assigned work orders
                                        </p>
                                    </div>
                                </div>
                                <div
                                    class="flex items-center gap-3 p-3 border rounded-lg"
                                >
                                    <Calendar class="w-5 h-5 text-primary" />
                                    <div>
                                        <p class="font-medium">Calendar</p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Schedule and track appointments
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <!-- Work Orders -->
            <TabsContent value="work-orders" class="space-y-6">
                <div class="grid gap-6">
                    <!-- Viewing Work Orders -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Managing Work Orders</CardTitle>
                            <CardDescription
                                >How to view and update your assigned work
                                orders</CardDescription
                            >
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <!-- Access Restrictions -->
                            <div>
                                <h3 class="text-lg font-semibold mb-3">
                                    What You Can See
                                </h3>
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2">
                                        <CheckCircle2
                                            class="w-4 h-4 text-green-600"
                                        />
                                        <span
                                            >Work orders where you are the
                                            assigned vendor</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <CheckCircle2
                                            class="w-4 h-4 text-green-600"
                                        />
                                        <span
                                            >Work orders with tasks assigned to
                                            you</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <CheckCircle2
                                            class="w-4 h-4 text-green-600"
                                        />
                                        <span
                                            >Work orders where you've uploaded
                                            attachments</span
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Status Guide -->
                            <div>
                                <h3 class="text-lg font-semibold mb-3">
                                    Work Order Statuses
                                </h3>
                                <div class="space-y-3">
                                    <div class="flex items-center gap-3">
                                        <Badge
                                            variant="secondary"
                                            class="bg-yellow-100 text-yellow-800"
                                            >Pending</Badge
                                        >
                                        <span class="text-sm"
                                            >Waiting to be started</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <Badge
                                            variant="secondary"
                                            class="bg-blue-100 text-blue-800"
                                            >In Progress</Badge
                                        >
                                        <span class="text-sm"
                                            >Currently being worked on</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <Badge
                                            variant="secondary"
                                            class="bg-green-100 text-green-800"
                                            >Completed</Badge
                                        >
                                        <span class="text-sm"
                                            >Work has been finished</span
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Editable Fields -->
                            <div>
                                <h3 class="text-lg font-semibold mb-3">
                                    What You Can Update
                                </h3>
                                <div
                                    class="grid grid-cols-1 md:grid-cols-2 gap-4"
                                >
                                    <div class="p-3 border rounded-lg">
                                        <p
                                            class="font-medium flex items-center gap-2"
                                        >
                                            <DollarSign
                                                class="w-4 h-4 text-primary"
                                            />
                                            Cost Estimate
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Provide or update the estimated cost
                                        </p>
                                    </div>
                                    <div class="p-3 border rounded-lg">
                                        <p
                                            class="font-medium flex items-center gap-2"
                                        >
                                            <Clock
                                                class="w-4 h-4 text-primary"
                                            />
                                            Time Estimate
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Set expected completion time
                                        </p>
                                    </div>
                                    <div class="p-3 border rounded-lg">
                                        <p
                                            class="font-medium flex items-center gap-2"
                                        >
                                            <Calendar
                                                class="w-4 h-4 text-primary"
                                            />
                                            Scheduled End Date
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            When work will be completed
                                        </p>
                                    </div>
                                    <div class="p-3 border rounded-lg">
                                        <p
                                            class="font-medium flex items-center gap-2"
                                        >
                                            <FileText
                                                class="w-4 h-4 text-primary"
                                            />
                                            Vendor Notes
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Add progress updates
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <Alert>
                                <AlertCircle class="w-4 h-4" />
                                <AlertDescription>
                                    Note: You cannot change work order status to
                                    closed or modify emergency status. These
                                    require coordinator approval.
                                </AlertDescription>
                            </Alert>
                        </CardContent>
                    </Card>

                    <!-- Search and Filter -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Finding Work Orders</CardTitle>
                            <CardDescription
                                >Use search and filters to locate specific work
                                orders</CardDescription
                            >
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-3">
                                <p>You can search and filter work orders by:</p>
                                <ul class="space-y-2">
                                    <li class="flex items-center gap-2">
                                        <ArrowRight
                                            class="w-4 h-4 text-primary"
                                        />
                                        <span>Work order number</span>
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <ArrowRight
                                            class="w-4 h-4 text-primary"
                                        />
                                        <span>Property address</span>
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <ArrowRight
                                            class="w-4 h-4 text-primary"
                                        />
                                        <span
                                            >Status (Open, Closed, In
                                            Progress)</span
                                        >
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <ArrowRight
                                            class="w-4 h-4 text-primary"
                                        />
                                        <span>Date range</span>
                                    </li>
                                </ul>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </TabsContent>

            <!-- Communication -->
            <TabsContent value="communication" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Communication Features</CardTitle>
                        <CardDescription
                            >Stay connected with tenants, coordinators, and
                            other vendors</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Communication Types -->
                        <div>
                            <h3 class="text-lg font-semibold mb-4">
                                Available Communication Channels
                            </h3>
                            <div class="grid gap-4">
                                <div class="p-4 border rounded-lg">
                                    <div class="flex items-start gap-3">
                                        <div
                                            class="p-2 bg-primary/10 rounded-lg"
                                        >
                                            <User
                                                class="w-5 h-5 text-primary"
                                            />
                                        </div>
                                        <div>
                                            <p class="font-medium">
                                                Tenant Communication
                                            </p>
                                            <p
                                                class="text-sm text-muted-foreground"
                                            >
                                                Direct messages with tenants
                                                about work order details and
                                                scheduling
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-4 border rounded-lg">
                                    <div class="flex items-start gap-3">
                                        <div
                                            class="p-2 bg-primary/10 rounded-lg"
                                        >
                                            <Settings
                                                class="w-5 h-5 text-primary"
                                            />
                                        </div>
                                        <div>
                                            <p class="font-medium">
                                                WOC Communication
                                            </p>
                                            <p
                                                class="text-sm text-muted-foreground"
                                            >
                                                Messages with Work Order
                                                Coordinators for clarifications
                                                and updates
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Best Practices -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Communication Best Practices
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Respond Promptly
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Reply to messages within 24 hours
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Be Professional
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Use courteous language and proper
                                            grammar
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Provide Clear Updates
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Include specific details about work
                                            progress
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Document Important Details
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Keep records of key conversations in
                                            the system
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- How to Send Messages -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Sending Messages
                            </h3>
                            <ol class="space-y-2">
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >1</span
                                    >
                                    <span
                                        >Open the work order details page</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >2</span
                                    >
                                    <span
                                        >Navigate to the Conversations tab</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >3</span
                                    >
                                    <span
                                        >Select the appropriate conversation
                                        type</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >4</span
                                    >
                                    <span
                                        >Type your message and click Send</span
                                    >
                                </li>
                            </ol>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <!-- Tasks -->
            <TabsContent value="tasks" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Task Management</CardTitle>
                        <CardDescription
                            >Efficiently manage and complete tasks within work
                            orders</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Understanding Tasks -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Understanding Tasks
                            </h3>
                            <p class="mb-4">
                                Tasks are specific items that need to be
                                completed within a work order. Each task
                                includes:
                            </p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="p-3 border rounded-lg">
                                    <p class="font-medium">Task Description</p>
                                    <p class="text-sm text-muted-foreground">
                                        Detailed explanation of work required
                                    </p>
                                </div>
                                <div class="p-3 border rounded-lg">
                                    <p class="font-medium">Priority Level</p>
                                    <p class="text-sm text-muted-foreground">
                                        High, Medium, or Low priority
                                    </p>
                                </div>
                                <div class="p-3 border rounded-lg">
                                    <p class="font-medium">Due Date</p>
                                    <p class="text-sm text-muted-foreground">
                                        When the task should be completed
                                    </p>
                                </div>
                                <div class="p-3 border rounded-lg">
                                    <p class="font-medium">Status</p>
                                    <p class="text-sm text-muted-foreground">
                                        Current progress indicator
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Priority Levels -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Task Priority Levels
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <Badge variant="destructive"
                                        >High Priority</Badge
                                    >
                                    <span class="text-sm"
                                        >Must be completed urgently, typically
                                        within 24-48 hours</span
                                    >
                                </div>
                                <div class="flex items-center gap-3">
                                    <Badge
                                        variant="secondary"
                                        class="bg-yellow-100 text-yellow-800"
                                        >Medium Priority</Badge
                                    >
                                    <span class="text-sm"
                                        >Complete within normal timeframe (3-5
                                        days)</span
                                    >
                                </div>
                                <div class="flex items-center gap-3">
                                    <Badge
                                        variant="secondary"
                                        class="bg-green-100 text-green-800"
                                        >Low Priority</Badge
                                    >
                                    <span class="text-sm"
                                        >Can be scheduled flexibly</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Updating Status -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Updating Task Status
                            </h3>
                            <ol class="space-y-2">
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >1</span
                                    >
                                    <span
                                        >Navigate to the Tasks section of the
                                        work order</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >2</span
                                    >
                                    <span
                                        >Find the task you want to update</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >3</span
                                    >
                                    <span>Click on the status dropdown</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >4</span
                                    >
                                    <span
                                        >Select the new status (In Progress,
                                        Completed)</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >5</span
                                    >
                                    <span
                                        >Add any relevant notes about the
                                        task</span
                                    >
                                </li>
                            </ol>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <!-- Invoices -->
            <TabsContent value="invoices" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Invoice Management</CardTitle>
                        <CardDescription
                            >Create and manage invoices for completed
                            work</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Creating Invoices -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Creating an Invoice
                            </h3>
                            <ol class="space-y-2">
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >1</span
                                    >
                                    <span
                                        >Complete all tasks in the work
                                        order</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >2</span
                                    >
                                    <span
                                        >Navigate to the Invoices section</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >3</span
                                    >
                                    <span>Click "Create New Invoice"</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >4</span
                                    >
                                    <span
                                        >Enter invoice details (amount,
                                        description)</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >5</span
                                    >
                                    <span>Attach supporting documents</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >6</span
                                    >
                                    <span>Submit for approval</span>
                                </li>
                            </ol>
                        </div>

                        <!-- Invoice Statuses -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Invoice Status Flow
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <Badge variant="secondary">Draft</Badge>
                                    <ArrowRight class="w-4 h-4" />
                                    <span class="text-sm"
                                        >Invoice is being prepared</span
                                    >
                                </div>
                                <div class="flex items-center gap-3">
                                    <Badge
                                        variant="secondary"
                                        class="bg-blue-100 text-blue-800"
                                        >Submitted</Badge
                                    >
                                    <ArrowRight class="w-4 h-4" />
                                    <span class="text-sm"
                                        >Sent for coordinator approval</span
                                    >
                                </div>
                                <div class="flex items-center gap-3">
                                    <Badge
                                        variant="secondary"
                                        class="bg-green-100 text-green-800"
                                        >Approved</Badge
                                    >
                                    <ArrowRight class="w-4 h-4" />
                                    <span class="text-sm"
                                        >Accepted and pending payment</span
                                    >
                                </div>
                                <div class="flex items-center gap-3">
                                    <Badge
                                        variant="secondary"
                                        class="bg-green-600 text-white"
                                        >Paid</Badge
                                    >
                                    <ArrowRight class="w-4 h-4" />
                                    <span class="text-sm"
                                        >Payment received</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Best Practices -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Invoice Best Practices
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Include Detailed Descriptions
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            List all work performed with
                                            specifics
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Attach Supporting Documents
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Include photos of completed work
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Match Estimates
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Ensure costs align with your
                                            estimates
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Submit Promptly
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Submit within 7 days of completion
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <!-- Calendar -->
            <TabsContent value="calendar" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Calendar & Scheduling</CardTitle>
                        <CardDescription
                            >Manage your schedule and appointments
                            efficiently</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Calendar Features -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Calendar Features
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="p-3 border rounded-lg">
                                    <p class="font-medium">
                                        View Scheduled Work
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        See all upcoming work orders
                                    </p>
                                </div>
                                <div class="p-3 border rounded-lg">
                                    <p class="font-medium">Set Availability</p>
                                    <p class="text-sm text-muted-foreground">
                                        Block out unavailable times
                                    </p>
                                </div>
                                <div class="p-3 border rounded-lg">
                                    <p class="font-medium">
                                        Track Appointments
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Manage tenant meetings
                                    </p>
                                </div>
                                <div class="p-3 border rounded-lg">
                                    <p class="font-medium">
                                        Multi-job Planning
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Optimize your route
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Scheduling Process -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Scheduling Work Orders
                            </h3>
                            <ol class="space-y-2">
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >1</span
                                    >
                                    <span>Open the work order details</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >2</span
                                    >
                                    <span>Click "Schedule Service"</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >3</span
                                    >
                                    <span>Select available date and time</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >4</span
                                    >
                                    <span>Confirm with tenant if required</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >5</span
                                    >
                                    <span>Save to calendar</span>
                                </li>
                            </ol>
                        </div>

                        <!-- Calendar Views -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Available Views
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <Calendar class="w-5 h-5 text-primary" />
                                    <div>
                                        <p class="font-medium">Month View</p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Overview of entire month
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <Calendar class="w-5 h-5 text-primary" />
                                    <div>
                                        <p class="font-medium">Week View</p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Detailed weekly schedule
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <Calendar class="w-5 h-5 text-primary" />
                                    <div>
                                        <p class="font-medium">Day View</p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Hour-by-hour breakdown
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <!-- Mobile -->
            <TabsContent value="mobile" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Mobile Access</CardTitle>
                        <CardDescription
                            >Use TX Work Order on your mobile
                            device</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Mobile Features -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Mobile Capabilities
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="flex items-start gap-3">
                                    <Smartphone
                                        class="w-5 h-5 text-primary mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Responsive Design
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Works on all screen sizes
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <Camera
                                        class="w-5 h-5 text-primary mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">Photo Upload</p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Take and upload directly
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <MessageSquare
                                        class="w-5 h-5 text-primary mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Real-time Updates
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Instant communication
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckSquare
                                        class="w-5 h-5 text-primary mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">Task Updates</p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Update status on the go
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Photo Upload Process -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Taking and Uploading Photos
                            </h3>
                            <ol class="space-y-2">
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >1</span
                                    >
                                    <span>Open work order on mobile</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >2</span
                                    >
                                    <span>Go to Attachments section</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >3</span
                                    >
                                    <span>Tap camera icon</span>
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >4</span
                                    >
                                    <span
                                        >Take photo or select from gallery</span
                                    >
                                </li>
                                <li class="flex gap-3">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 bg-primary text-primary-foreground rounded-full flex items-center justify-center text-sm"
                                        >5</span
                                    >
                                    <span>Add description and upload</span>
                                </li>
                            </ol>
                        </div>

                        <!-- Mobile Tips -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Mobile Best Practices
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <Lightbulb
                                        class="w-5 h-5 text-yellow-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Add to Home Screen
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Quick access like an app
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <Lightbulb
                                        class="w-5 h-5 text-yellow-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Enable Notifications
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Stay updated on new work orders
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <Lightbulb
                                        class="w-5 h-5 text-yellow-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Use WiFi for Uploads
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Save mobile data on large files
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <Lightbulb
                                        class="w-5 h-5 text-yellow-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Keep Device Charged
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Essential for field work
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <!-- Best Practices -->
            <TabsContent value="best-practices" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Best Practices for Success</CardTitle>
                        <CardDescription
                            >Professional guidelines to excel as a
                            vendor</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Response Time -->
                        <div>
                            <h3
                                class="text-lg font-semibold mb-3 flex items-center gap-2"
                            >
                                <Clock class="w-5 h-5 text-primary" />
                                Response Time Guidelines
                            </h3>
                            <div class="space-y-2">
                                <div
                                    class="p-3 border-l-4 border-primary bg-primary/5 rounded"
                                >
                                    <p class="font-medium">New Work Orders</p>
                                    <p class="text-sm text-muted-foreground">
                                        Respond within 2-4 hours during business
                                        hours
                                    </p>
                                </div>
                                <div
                                    class="p-3 border-l-4 border-primary bg-primary/5 rounded"
                                >
                                    <p class="font-medium">Messages</p>
                                    <p class="text-sm text-muted-foreground">
                                        Reply within 24 hours
                                    </p>
                                </div>
                                <div
                                    class="p-3 border-l-4 border-primary bg-primary/5 rounded"
                                >
                                    <p class="font-medium">
                                        Emergency Requests
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Respond immediately or within 1 hour
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Accurate Estimates -->
                        <div>
                            <h3
                                class="text-lg font-semibold mb-3 flex items-center gap-2"
                            >
                                <DollarSign class="w-5 h-5 text-primary" />
                                Providing Accurate Estimates
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <span
                                        >Include all materials and labor
                                        costs</span
                                    >
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <span
                                        >Account for potential
                                        complications</span
                                    >
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <span
                                        >Update promptly if scope changes</span
                                    >
                                </div>
                                <div class="flex items-start gap-3">
                                    <CheckCircle2
                                        class="w-5 h-5 text-green-600 mt-0.5"
                                    />
                                    <span>Document reasons for changes</span>
                                </div>
                            </div>
                        </div>

                        <!-- Common Mistakes -->
                        <div>
                            <h3
                                class="text-lg font-semibold mb-3 flex items-center gap-2"
                            >
                                <XCircle class="w-5 h-5 text-red-600" />
                                Common Mistakes to Avoid
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <AlertCircle
                                        class="w-5 h-5 text-red-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Not reading work order details
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Always review all information before
                                            starting
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <AlertCircle
                                        class="w-5 h-5 text-red-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Forgetting to update status
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Keep coordinators informed of
                                            progress
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <AlertCircle
                                        class="w-5 h-5 text-red-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Poor documentation
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Take photos and notes throughout the
                                            job
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <AlertCircle
                                        class="w-5 h-5 text-red-600 mt-0.5"
                                    />
                                    <div>
                                        <p class="font-medium">
                                            Missing appointments
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Use calendar reminders and confirm
                                            ahead
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Professional Tips -->
                        <div>
                            <h3
                                class="text-lg font-semibold mb-3 flex items-center gap-2"
                            >
                                <Star class="w-5 h-5 text-primary" />
                                Professional Excellence
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <Card class="border-primary/20">
                                    <CardHeader class="pb-3">
                                        <CardTitle class="text-base"
                                            >Communication</CardTitle
                                        >
                                    </CardHeader>
                                    <CardContent>
                                        <ul class="text-sm space-y-1">
                                            <li>
                                                • Be courteous and professional
                                            </li>
                                            <li>
                                                • Use clear, simple language
                                            </li>
                                            <li>
                                                • Confirm appointments in
                                                advance
                                            </li>
                                        </ul>
                                    </CardContent>
                                </Card>
                                <Card class="border-primary/20">
                                    <CardHeader class="pb-3">
                                        <CardTitle class="text-base"
                                            >Quality Work</CardTitle
                                        >
                                    </CardHeader>
                                    <CardContent>
                                        <ul class="text-sm space-y-1">
                                            <li>• Follow industry standards</li>
                                            <li>• Use quality materials</li>
                                            <li>• Clean up after completion</li>
                                        </ul>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <!-- Troubleshooting -->
            <TabsContent value="troubleshooting" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Troubleshooting Guide</CardTitle>
                        <CardDescription
                            >Solutions to common issues and how to get
                            help</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Common Issues -->
                        <div>
                            <h3 class="text-lg font-semibold mb-4">
                                Common Issues & Solutions
                            </h3>
                            <div class="space-y-4">
                                <!-- Login Issues -->
                                <div class="border rounded-lg p-4">
                                    <div class="flex items-start gap-3">
                                        <HelpCircle
                                            class="w-5 h-5 text-orange-600 mt-0.5"
                                        />
                                        <div class="flex-1">
                                            <p class="font-medium">
                                                Cannot log in
                                            </p>
                                            <ul
                                                class="mt-2 space-y-1 text-sm text-muted-foreground"
                                            >
                                                <li>
                                                    • Verify email and password
                                                    are correct
                                                </li>
                                                <li>
                                                    • Use "Forgot Password" to
                                                    reset
                                                </li>
                                                <li>
                                                    • Clear browser cache and
                                                    cookies
                                                </li>
                                                <li>
                                                    • Try a different browser
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <!-- Work Order Issues -->
                                <div class="border rounded-lg p-4">
                                    <div class="flex items-start gap-3">
                                        <HelpCircle
                                            class="w-5 h-5 text-orange-600 mt-0.5"
                                        />
                                        <div class="flex-1">
                                            <p class="font-medium">
                                                Work orders not showing
                                            </p>
                                            <ul
                                                class="mt-2 space-y-1 text-sm text-muted-foreground"
                                            >
                                                <li>• Refresh the page</li>
                                                <li>• Check your filters</li>
                                                <li>
                                                    • Verify you're assigned to
                                                    the work order
                                                </li>
                                                <li>
                                                    • Contact your coordinator
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <!-- Upload Issues -->
                                <div class="border rounded-lg p-4">
                                    <div class="flex items-start gap-3">
                                        <HelpCircle
                                            class="w-5 h-5 text-orange-600 mt-0.5"
                                        />
                                        <div class="flex-1">
                                            <p class="font-medium">
                                                Cannot upload photos
                                            </p>
                                            <ul
                                                class="mt-2 space-y-1 text-sm text-muted-foreground"
                                            >
                                                <li>
                                                    • Check file size (max 10MB)
                                                </li>
                                                <li>
                                                    • Ensure correct format
                                                    (JPG, PNG)
                                                </li>
                                                <li>
                                                    • Verify internet connection
                                                </li>
                                                <li>
                                                    • Try uploading one at a
                                                    time
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Getting Help -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Getting Help
                            </h3>
                            <div class="space-y-3">
                                <div
                                    class="flex items-center gap-3 p-3 border rounded-lg"
                                >
                                    <div class="p-2 bg-primary/10 rounded-lg">
                                        <BookOpen
                                            class="w-5 h-5 text-primary"
                                        />
                                    </div>
                                    <div>
                                        <p class="font-medium">
                                            Check this guide
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            Most answers can be found here
                                        </p>
                                    </div>
                                </div>
                                <div
                                    class="flex items-center gap-3 p-3 border rounded-lg"
                                >
                                    <div class="p-2 bg-primary/10 rounded-lg">
                                        <MessageSquare
                                            class="w-5 h-5 text-primary"
                                        />
                                    </div>
                                    <div>
                                        <p class="font-medium">
                                            Contact your coordinator
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            For work order specific issues
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- FAQs -->
                        <div>
                            <h3 class="text-lg font-semibold mb-3">
                                Frequently Asked Questions
                            </h3>
                            <div class="space-y-4">
                                <div class="border-b pb-3">
                                    <p class="font-medium mb-1">
                                        How do I change my password?
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Go to your profile settings and click
                                        "Change Password"
                                    </p>
                                </div>
                                <div class="border-b pb-3">
                                    <p class="font-medium mb-1">
                                        Can I decline a work order?
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Contact your coordinator to discuss
                                        reassignment
                                    </p>
                                </div>
                                <div class="border-b pb-3">
                                    <p class="font-medium mb-1">
                                        How long do I have to submit an invoice?
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Submit within 7 days of work completion
                                        for prompt payment
                                    </p>
                                </div>
                                <div class="border-b pb-3">
                                    <p class="font-medium mb-1">
                                        What if I need to reschedule?
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Update the scheduled date and notify the
                                        tenant and coordinator
                                    </p>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <!-- Video Tutorials -->
            <TabsContent value="video-tutorials" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Video Training Resources</CardTitle>
                        <CardDescription
                            >Watch comprehensive video tutorials to master the
                            TX Work Order system</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Introduction Video -->
                        <div>
                            <h3
                                class="text-lg font-semibold mb-4 flex items-center gap-2"
                            >
                                <PlayCircle class="w-5 h-5 text-primary" />
                                Getting Started Videos
                            </h3>
                            <div class="grid gap-4">
                                <Card class="border-primary/20">
                                    <CardHeader>
                                        <div
                                            class="flex items-start justify-between"
                                        >
                                            <div>
                                                <CardTitle class="text-base"
                                                    >TX Work Order - Vendor
                                                    Training</CardTitle
                                                >
                                                <CardDescription
                                                    >Complete guide for vendors
                                                    using the TX Work Order
                                                    system</CardDescription
                                                >
                                            </div>
                                            <Badge variant="secondary">
                                                <Clock class="w-3 h-3 mr-1" />
                                                10 min
                                            </Badge>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div class="rounded-lg overflow-hidden">
                                            <div class="aspect-video">
                                                <iframe
                                                    src="https://www.youtube.com/embed/BB5pTiAUbxo"
                                                    title="TX Work Order - Vendor Training"
                                                    frameborder="0"
                                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                                    allowfullscreen
                                                    class="w-full h-full"
                                                ></iframe>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>

                        <!-- Video Tips -->
                        <Alert>
                            <Info class="w-4 h-4" />
                            <AlertDescription>
                                <strong>Tips for watching videos:</strong>
                                <ul
                                    class="list-disc list-inside mt-2 space-y-1"
                                >
                                    <li>
                                        Watch videos in order for best learning
                                        experience
                                    </li>
                                    <li>
                                        Use fullscreen mode for better
                                        visibility
                                    </li>
                                    <li>Enable captions if available</li>
                                    <li>
                                        Take notes while watching for future
                                        reference
                                    </li>
                                </ul>
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                </Card>
            </TabsContent>
        </Tabs>
    </div>
</template>
