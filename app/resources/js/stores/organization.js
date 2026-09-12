import { defineStore } from 'pinia';
import axios from 'axios';

export const useOrganizationStore = defineStore('organization', {
    state: () => ({
        organization: null,
        loading: false,
        saving: false,
        statusLoading: false,
        error: null,
        fieldErrors: {},
        parseStatus: null,
        // true, пока идёт первый парсинг новой карточки (business_id сменился):
        // старые данные скрыты и не восстанавливаются при ошибке.
        freshParse: false,
    }),

    getters: {
        hasOrganization: (state) => state.organization !== null,
        isParsing: (state) => ['queued', 'running'].includes(state.organization?.parse_status),
        hasKnownData: (state) => Boolean(
            state.organization?.title
            || state.organization?.address
            || state.organization?.rating !== null
            || (state.organization?.ratings_count ?? 0) > 0,
        ),
        // Сброс интерфейса: идёт парсинг новой карточки и валидных данных нет.
        isResetting: (state) => {
            if (!['queued', 'running'].includes(state.organization?.parse_status)) {
                return false;
            }

            if (state.freshParse) {
                return true;
            }

            return !(
                state.organization?.title
                || state.organization?.address
                || state.organization?.rating !== null
                || (state.organization?.ratings_count ?? 0) > 0
            );
        },
    },

    actions: {
        clearErrors() {
            this.error = null;
            this.fieldErrors = {};
        },

        async fetch() {
            this.loading = true;
            this.error = null;

            try {
                const { data } = await axios.get('/api/organization');
                this.organization = data.data;

                if (!this.isParsing) {
                    this.freshParse = false;
                }
            } catch {
                this.error = 'Не удалось загрузить организацию.';
            } finally {
                this.loading = false;
            }
        },

        async save(url) {
            this.saving = true;
            this.clearErrors();

            const previousBusinessId = this.organization?.business_id ?? null;

            try {
                const { data } = await axios.post('/api/organization', { url });
                this.freshParse = previousBusinessId === null
                    || String(previousBusinessId) !== String(data.data?.business_id);
                this.organization = data.data;
                return true;
            } catch (error) {
                if (error.response?.status === 422) {
                    this.fieldErrors = error.response.data.errors ?? {};
                    this.error = this.fieldErrors.url?.[0] ?? 'Проверьте ссылку.';
                } else {
                    this.error = error.response?.data?.message ?? 'Не удалось сохранить ссылку.';
                }

                return false;
            } finally {
                this.saving = false;
            }
        },

        async refresh() {
            this.saving = true;
            this.clearErrors();

            try {
                const { data } = await axios.post('/api/organization/refresh');
                this.organization = data.data;
                return true;
            } catch (error) {
                this.error = error.response?.data?.message
                    ?? (error.response?.status === 409
                        ? 'Парсинг уже выполняется.'
                        : 'Не удалось запустить обновление.');

                return false;
            } finally {
                this.saving = false;
            }
        },

        async fetchStatus() {
            if (this.statusLoading) {
                return false;
            }

            this.statusLoading = true;

            try {
                const { data } = await axios.get('/api/organization/parse-status');
                this.parseStatus = data.data;

                if (this.organization && this.parseStatus) {
                    this.organization.parse_status = this.parseStatus.parse_status;
                    this.organization.parse_error = this.parseStatus.parse_error;
                }

                if (this.hasOrganization && !this.isParsing) {
                    await this.fetch();
                }

                return true;
            } catch {
                this.error = 'Не удалось получить статус парсинга.';
                return false;
            } finally {
                this.statusLoading = false;
            }
        },
    },
});
