<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
});
</script>
<template>
    <Head :title="title" />
    <div class="bg-white h-[90vh]">
        <iframe
            src="https://app.relevanceai.com/agents/bcbe5a/4e86ee120de5-498d-a5f8-c4ff0eca63a7/1a1a3e95-4012-498a-8cc5-5a4fd8bc8f3d/embed-chat?starting_message_prompts=The+toilet+is+clogged.&hide_tool_steps=false&hide_file_uploads=true&hide_conversation_list=false&bubble_style=agent&primary_color=%238e9de6&bubble_icon=notebook%2Fdeploy&input_placeholder_text=Type+property+issues...&hide_logo=true&hide_description=false&theme=light"
            width="100%"
            height="100%"
            frameborder="0"
        ></iframe>
    </div>
</template>
