import { createRouter, createWebHistory } from 'vue-router';
import LoginView from '@/views/LoginView.vue';
import SettingsView from '@/views/SettingsView.vue';
import ReviewsView from '@/views/ReviewsView.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';

const routes = [
    { path: '/login', name: 'login', component: LoginView, meta: { guest: true } },
    {
        path: '/',
        component: AppLayout,
        meta: { requiresAuth: true },
        children: [
            { path: 'settings', name: 'settings', component: SettingsView },
            { path: 'reviews', name: 'reviews', component: ReviewsView },
            { path: '', redirect: { name: 'settings' } },
        ],
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.initialized) {
        await auth.fetchUser();
    }

    if (to.meta.requiresAuth && !auth.user) {
        return { name: 'login' };
    }

    if (to.meta.guest && auth.user) {
        return { name: 'settings' };
    }
});

export default router;
