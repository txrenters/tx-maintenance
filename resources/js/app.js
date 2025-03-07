import './bootstrap';
import '../css/app.css';


import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { Separator } from '@/Components/ui/separator';
import BreadcrumbContainer from '@/Partials/Breadcrumb.vue';
import { Head, Link } from '@inertiajs/vue3';
import NavLink from '@/Components/NavLink.vue';
import { Badge } from "@/Components/ui/badge";
import { Label } from "@/Components/ui/label";
import { Checkbox } from "@/Components/ui/checkbox";
import { Textarea } from '@/Components/ui/textarea';
import { Switch } from '@/Components/ui/switch'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import Toaster from '@/Components/ui/toast/Toaster.vue'
import Pagination from '@/Components/Pagination.vue';
import PaginationResultRange from '@/Components/PaginationResultRange.vue';
import SearchBar from '@/Components/SearchBar.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { PlusCircle, Loader2, MoreHorizontal } from "lucide-vue-next";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Components/ui/alert-dialog'

import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/Components/ui/breadcrumb'
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/Components/ui/collapsible'

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu'
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/Components/ui/avatar'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog'
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/Components/ui/card'
import { RadioGroup, RadioGroupItem } from '@/Components/ui/radio-group'
import {
    FormControl,
    FormDescription,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form'

import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table'
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select'

import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css'
import Multiselect from 'vue-multiselect'
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/components/ui/tooltip";
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {

        const app = createApp({ render: () => h(App, props) });

        return app
            .use(plugin)
            .use(ZiggyVue)
            .component("Head", Head)
            .component("ScrollArea", ScrollArea)
            .component("ScrollBar", ScrollBar)
            .component("Tooltip", Tooltip)
            .component("TooltipContent", TooltipContent)
            .component("TooltipProvider", TooltipProvider)
            .component("TooltipTrigger", TooltipTrigger)

            .component("VueDatePicker", VueDatePicker)
            .component("Multiselect", Multiselect)
            .component("Link", Link)
            .component("NavLink", NavLink)
            .component("Button", Button)
            .component("Input", Input)
            .component("Label", Label)
            .component("Badge", Badge)
            .component("Loader2", Loader2)
            .component("Checkbox", Checkbox)
            .component("Textarea", Textarea)
            .component("Switch", Switch)
            .component("Toaster", Toaster)
            .component("PlusCircle", PlusCircle)
            .component("MoreHorizontal", MoreHorizontal)
            .component("Skeleton", Skeleton)
            .component("AlertDialog", AlertDialog)
            .component("AlertDialogAction", AlertDialogAction)
            .component("AlertDialogCancel", AlertDialogCancel)
            .component("AlertDialogContent", AlertDialogContent)
            .component("AlertDialogDescription", AlertDialogDescription)
            .component("AlertDialogFooter", AlertDialogFooter)
            .component("AlertDialogHeader", AlertDialogHeader)
            .component("AlertDialogTitle", AlertDialogTitle)
            .component("AlertDialogTrigger", AlertDialogTrigger)
            .component("Select", Select)
            .component("SelectContent", SelectContent)
            .component("SelectGroup", SelectGroup)
            .component("SelectItem", SelectItem)
            .component("SelectLabel", SelectLabel)
            .component("SelectTrigger", SelectTrigger)
            .component("SelectValue", SelectValue)
            .component("Pagination", Pagination)
            .component("PaginationResultRange", PaginationResultRange)
            .component("SearchBar", SearchBar)
            .component("Table", Table)
            .component("TableBody", TableBody)
            .component("TableCaption", TableCaption)
            .component("TableCell", TableCell)
            .component("TableHead", TableHead)
            .component("TableHeader", TableHeader)
            .component("TableRow", TableRow)
            .component("FormControl", FormControl)
            .component("FormDescription", FormDescription)
            .component("FormField", FormField)
            .component("FormItem", FormItem)
            .component("FormLabel", FormLabel)
            .component("FormMessage", FormMessage)
            .component("RadioGroup", RadioGroup)
            .component("RadioGroupItem", RadioGroupItem)
            .component("Card", Card)
            .component("CardContent", CardContent)
            .component("CardDescription", CardDescription)
            .component("CardFooter", CardFooter)
            .component("CardHeader", CardHeader)
            .component("CardTitle", CardTitle)
            .component("Dialog", Dialog)
            .component("DialogContent", DialogContent)
            .component("DialogDescription", DialogDescription)
            .component("DialogFooter", DialogFooter)
            .component("DialogHeader", DialogHeader)
            .component("DialogTitle", DialogTitle)
            .component("DialogTrigger", DialogTrigger)
            .component("Tabs", Tabs)
            .component("TabsContent", TabsContent)
            .component("TabsList", TabsList)
            .component("TabsTrigger", TabsTrigger)
            .component("Breadcrumb", Breadcrumb)
            .component("BreadcrumbItem", BreadcrumbItem)
            .component("BreadcrumbLink", BreadcrumbLink)
            .component("BreadcrumbList", BreadcrumbList)
            .component("BreadcrumbPage", BreadcrumbPage)
            .component("BreadcrumbSeparator", BreadcrumbSeparator)
            .component("BreadcrumbContainer", BreadcrumbContainer)
            .component("Collapsible", Collapsible)
            .component("CollapsibleContent", CollapsibleContent)
            .component("CollapsibleTrigger", CollapsibleTrigger)
            .component("DropdownMenu", DropdownMenu)
            .component("DropdownMenuContent", DropdownMenuContent)
            .component("DropdownMenuGroup", DropdownMenuGroup)
            .component("DropdownMenuItem", DropdownMenuItem)
            .component("DropdownMenuLabel", DropdownMenuLabel)
            .component("DropdownMenuSeparator", DropdownMenuSeparator)
            .component("DropdownMenuShortcut", DropdownMenuShortcut)
            .component("DropdownMenuTrigger", DropdownMenuTrigger)
            .component("Separator", Separator)
            .component("Avatar", Avatar)
            .component("AvatarFallback", AvatarFallback)
            .component("AvatarImage", AvatarImage)

            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
