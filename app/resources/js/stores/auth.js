import { defineStore } from 'pinia';
import axios from 'axios';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        loading: false,
        error: null,
        initialized: false,
    }),

    actions: {
        async fetchUser() {
            this.loading = true;
            this.error = null;

            try {
                const response = await axios.get('/api/user');
                this.user = response.data;
            } catch (error) {
                if (error.response?.status === 401) {
                    this.user = null;
                } else {
                    this.error = 'Не удалось проверить сессию.';
                }
            } finally {
                this.initialized = true;
                this.loading = false;
            }
        },

        async login(credentials) {
            this.loading = true;
            this.error = null;

            try {
                await axios.get('/sanctum/csrf-cookie');
                await axios.post('/api/login', credentials);
                await this.fetchUser();
            } catch (error) {
                this.error = error.response?.data?.errors?.email?.[0]
                    ?? error.response?.data?.message
                    ?? 'Не удалось войти.';
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async logout() {
            this.loading = true;

            try {
                await axios.post('/api/logout');
            } finally {
                this.user = null;
                this.loading = false;
            }
        },
    },
});
