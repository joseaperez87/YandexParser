<script setup>
import { reactive } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const auth = useAuthStore();
const form = reactive({ email: '', password: '' });

async function submit() {
    try {
        await auth.login(form);
        await router.push({ name: 'settings' });
    } catch {
        // Ошибка уже сохранена в store и отображается в форме.
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
        <div class="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h1 class="mb-1 text-xl font-semibold text-slate-900">Вход</h1>
            <p class="mb-6 text-sm text-slate-500">Авторизация в панели управления.</p>

            <form class="space-y-4" @submit.prevent="submit">
                <label class="block text-sm font-medium text-slate-700">
                    Email
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 outline-none focus:border-slate-500"
                    >
                </label>

                <label class="block text-sm font-medium text-slate-700">
                    Пароль
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 outline-none focus:border-slate-500"
                    >
                </label>

                <p v-if="auth.error" class="text-sm text-red-600">{{ auth.error }}</p>

                <button
                    type="submit"
                    :disabled="auth.loading"
                    class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ auth.loading ? 'Входим...' : 'Войти' }}
                </button>
            </form>
        </div>
    </div>
</template>
