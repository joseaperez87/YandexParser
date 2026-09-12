<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useOrganizationStore } from '@/stores/organization';
import { useParsePolling } from '@/composables/useParsePolling';
import ParseProgress from '@/components/ParseProgress.vue';
import { formatNumber, formatRating, parseErrorText, statusLabel, statusTone } from '@/utils/format';

const store = useOrganizationStore();
const form = reactive({ url: '' });
const showQueueHint = ref(false);
const saved = computed(() => store.organization);
const run = computed(() => store.parseStatus?.run ?? null);
const parseError = computed(() => parseErrorText(
    saved.value?.parse_status,
    saved.value?.parse_error,
));

const polling = useParsePolling(store, {
    onStaleQueue: () => { showQueueHint.value = true; },
});

function maybeStartPolling() {
    if (store.isParsing) {
        showQueueHint.value = false;
        polling.start();
    }
}

onMounted(async () => {
    await store.fetch();

    if (saved.value) {
        form.url = saved.value.url;
    }

    maybeStartPolling();
});

watch(
    () => store.organization,
    (organization) => {
        if (organization && !form.url) {
            form.url = organization.url;
        }
    },
);

watch(() => store.isParsing, (active) => {
    if (active) {
        showQueueHint.value = false;
        polling.start();
    }
});

async function submit() {
    const ok = await store.save(form.url);

    if (ok) {
        form.url = saved.value?.url ?? form.url;
        maybeStartPolling();
    }
}

async function refresh() {
    const ok = await store.refresh();

    if (ok) {
        maybeStartPolling();
    }
}

function updateStatus() {
    store.fetchStatus();
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="mb-1 text-xl font-semibold text-slate-900">Настройки</h1>
            <p class="text-sm text-slate-500">
                Вставьте ссылку на карточку организации в Яндекс.Картах.
            </p>
        </div>

        <form
            class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
            @submit.prevent="submit"
        >
            <label class="block text-sm font-medium text-slate-700">
                Ссылка на организацию
                <input
                    v-model="form.url"
                    type="url"
                    placeholder="https://yandex.ru/maps/org/..."
                    required
                    class="mt-1 w-full rounded-md border px-3 py-2 text-slate-900 outline-none focus:border-slate-500"
                    :class="store.fieldErrors.url ? 'border-red-400' : 'border-slate-300'"
                >
            </label>

            <p v-if="store.error" class="text-sm text-red-600">{{ store.error }}</p>
            <p v-else-if="store.statusLoading" class="text-sm text-slate-500">Обновляем статус…</p>

            <button
                type="submit"
                :disabled="store.saving"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
            >
                {{ store.saving ? 'Сохраняем…' : 'Сохранить' }}
            </button>
        </form>

        <section
            v-if="saved && store.isResetting"
            class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Новая организация</h2>
                    <p class="break-all text-sm text-slate-500">{{ saved.url }}</p>
                </div>

                <span
                    class="rounded-full px-3 py-1 text-xs font-medium"
                    :class="statusTone(saved.parse_status)"
                >
                    {{ statusLabel(saved.parse_status) }}
                </span>
            </div>

            <ParseProgress
                :status="saved.parse_status"
                :run="run"
                :show-queue-hint="showQueueHint"
            />

            <p v-if="parseError && !store.isParsing" class="text-sm text-red-600">{{ parseError }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    :disabled="store.statusLoading"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                    @click="updateStatus"
                >
                    Обновить статус
                </button>

                <RouterLink
                    class="text-sm font-medium text-slate-700 underline hover:text-slate-900"
                    :to="{ name: 'reviews' }"
                >
                    Перейти к отзывам
                </RouterLink>
            </div>
        </section>

        <section
            v-else-if="saved"
            class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">
                        {{ saved.title || 'Организация' }}
                    </h2>
                    <p class="text-sm text-slate-500">{{ saved.address || 'Адрес не указан' }}</p>
                </div>

                <span
                    class="rounded-full px-3 py-1 text-xs font-medium"
                    :class="statusTone(saved.parse_status)"
                >
                    {{ statusLabel(saved.parse_status) }}
                </span>
            </div>

            <ParseProgress
                v-if="store.isParsing"
                :status="saved.parse_status"
                :run="run"
                :show-queue-hint="showQueueHint"
            />

            <dl class="grid grid-cols-3 gap-4 text-center">
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-xs text-slate-500">Средний рейтинг</dt>
                    <dd class="text-lg font-semibold text-slate-900">{{ formatRating(saved.rating) }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-xs text-slate-500">Оценок</dt>
                    <dd class="text-lg font-semibold text-slate-900">{{ formatNumber(saved.ratings_count) }}</dd>
                </div>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-xs text-slate-500">Отзывов</dt>
                    <dd class="text-lg font-semibold text-slate-900">{{ formatNumber(saved.reviews_count) }}</dd>
                </div>
            </dl>

            <p v-if="parseError && !store.isParsing" class="text-sm text-red-600">{{ parseError }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    :disabled="store.saving || store.isParsing"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                    @click="refresh"
                >
                    Обновить данные
                </button>

                <button
                    type="button"
                    :disabled="store.statusLoading"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                    @click="updateStatus"
                >
                    Обновить статус
                </button>

                <RouterLink
                    class="text-sm font-medium text-slate-700 underline hover:text-slate-900"
                    :to="{ name: 'reviews' }"
                >
                    Перейти к отзывам
                </RouterLink>
            </div>
        </section>
    </div>
</template>
