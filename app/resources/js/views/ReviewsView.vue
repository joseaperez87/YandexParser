<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useOrganizationStore } from '@/stores/organization';
import { useReviewsStore } from '@/stores/reviews';
import { useParsePolling } from '@/composables/useParsePolling';
import ParseProgress from '@/components/ParseProgress.vue';
import { formatDate, formatNumber, formatRating, parseErrorText, statusLabel } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const organization = useOrganizationStore();
const reviews = useReviewsStore();
const showQueueHint = ref(false);

const page = computed(() => Number(route.query.page) || 1);
const organizationData = computed(() => organization.organization);
const lastPage = computed(() => reviews.meta.last_page ?? 1);
const run = computed(() => organization.parseStatus?.run ?? null);
const parseError = computed(() => parseErrorText(
    organizationData.value?.parse_status,
    organizationData.value?.parse_error,
));

const polling = useParsePolling(organization, {
    onTerminal: () => load(page.value),
    onStaleQueue: () => { showQueueHint.value = true; },
});

const pages = computed(() => {
    const total = lastPage.value;
    const current = page.value;
    const result = [];

    for (let index = 1; index <= total; index++) {
        const edge = index === 1 || index === total;
        const near = Math.abs(index - current) <= 2;

        if (edge || near) {
            result.push(index);
        } else if (result[result.length - 1] !== '…') {
            result.push('…');
        }
    }

    return result;
});

onMounted(async () => {
    await organization.fetch();

    if (organization.isParsing) {
        polling.start();
    }

    await load(page.value);
});

watch(page, (value) => {
    load(value);
});

watch(() => organization.isParsing, (active) => {
    if (active) {
        showQueueHint.value = false;
        polling.start();
    }
});

async function load(current) {
    await reviews.fetchPage(current);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goTo(target) {
    if (target < 1 || target > lastPage.value || target === page.value) {
        return;
    }

    router.push({ name: 'reviews', query: { page: target } });
}

async function updateStatus() {
    await organization.fetchStatus();
    await load(page.value);
}

async function retry() {
    const ok = await organization.refresh();

    if (ok) {
        showQueueHint.value = false;
        polling.start();
    }
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="mb-1 text-xl font-semibold text-slate-900">Отзывы</h1>
            <p class="text-sm text-slate-500">Данные организации и отзывы с Яндекс.Карт.</p>
        </div>

        <p v-if="organization.loading && !organizationData" class="text-sm text-slate-500">
            Загружаем данные…
        </p>

        <div
            v-else-if="!organizationData"
            class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm"
        >
            Организация не подключена. Добавьте ссылку в
            <RouterLink class="underline" :to="{ name: 'settings' }">настройках</RouterLink>.
        </div>

        <template v-else>
            <section class="space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">
                            {{ organizationData.title || 'Организация' }}
                        </h2>
                        <p class="text-sm text-slate-500">
                            {{ organizationData.address || 'Адрес не указан' }}
                        </p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        {{ statusLabel(organizationData.parse_status) }}
                    </span>
                </div>

                <dl class="grid grid-cols-3 gap-4 text-center">
                    <div class="rounded-md bg-slate-50 p-3">
                        <dt class="text-xs text-slate-500">Средний рейтинг</dt>
                        <dd class="text-lg font-semibold text-slate-900">
                            {{ formatRating(organizationData.rating) }}
                        </dd>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <dt class="text-xs text-slate-500">Оценок</dt>
                        <dd class="text-lg font-semibold text-slate-900">
                            {{ formatNumber(organizationData.ratings_count) }}
                        </dd>
                    </div>
                    <div class="rounded-md bg-slate-50 p-3">
                        <dt class="text-xs text-slate-500">Отзывов</dt>
                        <dd class="text-lg font-semibold text-slate-900">
                            {{ formatNumber(organizationData.reviews_count) }}
                        </dd>
                    </div>
                </dl>

                <ParseProgress
                    v-if="organization.isParsing"
                    :status="organizationData.parse_status"
                    :run="run"
                    :show-queue-hint="showQueueHint"
                />

                <p v-if="parseError && !organization.isParsing" class="text-sm text-red-600">
                    {{ parseError }}
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        :disabled="organization.statusLoading"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        @click="updateStatus"
                    >
                        Обновить статус
                    </button>

                    <button
                        type="button"
                        :disabled="organization.saving || organization.isParsing"
                        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        @click="retry"
                    >
                        Повторить парсинг
                    </button>
                </div>
            </section>

            <section class="space-y-4">
                <div
                    v-if="organization.isParsing"
                    class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm"
                >
                    <p class="font-medium text-slate-700">Идёт парсинг отзывов…</p>
                    <p class="mt-1">Список появится автоматически, когда парсинг завершится.</p>
                </div>
                <p v-else-if="reviews.loading" class="text-sm text-slate-500">Загружаем отзывы…</p>
                <p v-else-if="reviews.error" class="text-sm text-red-600">{{ reviews.error }}</p>
                <p
                    v-else-if="reviews.items.length === 0"
                    class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm"
                >
                    Отзывы пока не загружены.
                </p>

                <ul v-else class="space-y-3">
                    <li
                        v-for="review in reviews.items"
                        :key="review.id"
                        class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                    >
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <span class="font-medium text-slate-900">{{ review.author || 'Аноним' }}</span>
                            <span class="flex items-center gap-3 text-sm text-slate-500">
                                <span>{{ formatDate(review.published_at) }}</span>
                                <span class="rounded bg-slate-100 px-2 py-0.5 font-medium text-slate-700">
                                    {{ review.rating ?? '—' }}
                                </span>
                            </span>
                        </div>
                        <p class="whitespace-pre-line text-sm text-slate-700">
                            {{ review.text || 'Без текста' }}
                        </p>
                    </li>
                </ul>

                <nav
                    v-if="lastPage > 1 && !reviews.loading && !organization.isParsing"
                    class="flex items-center justify-center gap-1"
                >
                    <button
                        type="button"
                        :disabled="page <= 1"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="goTo(page - 1)"
                    >
                        Назад
                    </button>

                    <template v-for="(item, index) in pages" :key="`${item}-${index}`">
                        <span v-if="item === '…'" class="px-2 text-slate-400">…</span>
                        <button
                            v-else
                            type="button"
                            class="min-w-9 rounded-md px-3 py-1.5 text-sm"
                            :class="item === page
                                ? 'bg-slate-900 text-white'
                                : 'border border-slate-300 text-slate-700 hover:bg-slate-50'"
                            @click="goTo(item)"
                        >
                            {{ item }}
                        </button>
                    </template>

                    <button
                        type="button"
                        :disabled="page >= lastPage"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="goTo(page + 1)"
                    >
                        Вперёд
                    </button>
                </nav>
            </section>
        </template>
    </div>
</template>
