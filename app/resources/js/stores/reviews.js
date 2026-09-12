import { defineStore } from 'pinia';
import axios from 'axios';

export const useReviewsStore = defineStore('reviews', {
    state: () => ({
        items: [],
        meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
        loading: false,
        error: null,
    }),

    actions: {
        async fetchPage(page = 1) {
            this.loading = true;
            this.error = null;

            try {
                const { data } = await axios.get('/api/reviews', { params: { page } });
                this.items = data.data;
                this.meta = data.meta;
            } catch {
                this.items = [];
                this.error = 'Не удалось загрузить отзывы.';
            } finally {
                this.loading = false;
            }
        },
    },
});
