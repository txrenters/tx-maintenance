<script setup>
const startYear = 2025;
const currentYear = new Date().getFullYear();
</script>
<template>
    <footer class="bg-gray-50 border-t mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="text-center text-sm text-gray-600">
                <p>
                    &copy;
                    {{
                        startYear === currentYear
                            ? currentYear
                            : `${startYear}–${currentYear}`
                    }}
                    <a
                        href="https://www.texasrenters.com/"
                        target="_blank"
                        class="font-bold text-primary"
                        >TexasRenters.com</a
                    >. All rights reserved.
                </p>
                <p class="mt-2">Need help? Message us at 281-248-8018</p>
                <p>Developed and maintained by Texas Renters IT Department.</p>
            </div>
        </div>
    </footer>
</template>
