<script setup>
import { computed } from 'vue';
import { formatNumber, statusLabel } from '@/utils/format';

const props = defineProps({
    status: { type: String, default: null },
    run: { type: Object, default: null },
    showQueueHint: { type: Boolean, default: false },
});

const percent = computed(() => {
    if (!props.run?.total) {
        return null;
    }

    return Math.min(100, Math.round((props.run.progress / props.run.total) * 100));
});

const isQueued = computed(() => props.status === 'queued');
</script>

<template>
    <div class="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-4">
        <p class="text-sm font-medium text-amber-800">
            {{ isQueued ? 'В очереди на парсинг…' : 'Идёт парсинг отзывов…' }}
            <span class="font-normal text-amber-700">({{ statusLabel(status) }})</span>
        </p>
        <div class="h-2 w-full overflow-hidden rounded-full bg-amber-200">
            <div
                class="h-full rounded-full bg-amber-500 transition-all"
                :class="{ 'animate-pulse': percent === null }"
                :style="{ width: percent === null ? '25%' : `${percent}%` }"
            />
        </div>
        <p v-if="run && run.total" class="text-xs text-amber-700">
            Собрано {{ formatNumber(run.progress) }} из {{ formatNumber(run.total) }}
        </p>
        <p v-else class="text-xs text-amber-700">
            Запускаем задачу парсинга…
        </p>
        <p v-if="showQueueHint" class="text-xs text-amber-700">
            Очередь не обрабатывается? Запустите <code>php artisan queue:work</code>.
        </p>
    </div>
</template>
