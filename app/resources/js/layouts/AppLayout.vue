<script setup>
import { RouterLink, RouterView } from 'vue-router';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

function linkClass(name) {
    return route.name === name
        ? 'font-medium text-slate-900'
        : 'text-slate-600 hover:text-slate-900';
}

async function logout() {
    await auth.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
                <span class="font-semibold text-slate-900">Yandex Parser</span>
                <nav class="flex items-center gap-4 text-sm">
                    <RouterLink :class="linkClass('settings')" :to="{ name: 'settings' }">
                        Настройки
                    </RouterLink>
                    <RouterLink :class="linkClass('reviews')" :to="{ name: 'reviews' }">
                        Отзывы
                    </RouterLink>
                    <span v-if="auth.user" class="text-slate-400">{{ auth.user.name }}</span>
                    <button class="text-slate-600 hover:text-slate-900" type="button" @click="logout">
                        Выйти
                    </button>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8">
            <RouterView />
        </main>
    </div>
</template>
