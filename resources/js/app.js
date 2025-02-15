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
            .component("Link", Link)
            .component("NavLink", NavLink)
            .component("Button", Button)
            .component("Input", Input)
            .component("Label", Label)
            .component("Badge", Badge)
            .component("Checkbox", Checkbox)
            .component("Textarea", Textarea)
            .component("Switch", Switch)
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
