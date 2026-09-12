const STATUS_LABELS = {
    pending: 'Ожидает',
    queued: 'В очереди',
    running: 'Идёт парсинг',
    ready: 'Готово',
    partial: 'Частично',
    failed: 'Ошибка',
    source_changed: 'Источник изменился',
    empty: 'Нет данных',
};

const STATUS_TONES = {
    pending: 'bg-slate-100 text-slate-600',
    queued: 'bg-blue-100 text-blue-700',
    running: 'bg-amber-100 text-amber-700',
    ready: 'bg-emerald-100 text-emerald-700',
    partial: 'bg-amber-100 text-amber-700',
    failed: 'bg-red-100 text-red-700',
    source_changed: 'bg-red-100 text-red-700',
    empty: 'bg-red-100 text-red-700',
};

export function formatDate(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(date);
}

export function formatRating(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toFixed(1);
}

export function formatNumber(value) {
    return new Intl.NumberFormat('ru-RU').format(value ?? 0);
}

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? status ?? '—';
}

export function statusTone(status) {
    return STATUS_TONES[status] ?? 'bg-slate-100 text-slate-600';
}

const PARSE_ERROR_HINTS = {
    failed: 'Ошибка парсинга. Попробуйте повторить.',
    source_changed: 'Яндекс изменил разметку источника. Повторите позже.',
    empty: 'Источник вернул пустой ответ. Попробуйте повторить.',
    partial: 'Собрано частично: часть отзывов не удалось получить.',
};

export function parseErrorText(status, parseError) {
    if (parseError) {
        return parseError;
    }

    return PARSE_ERROR_HINTS[status] ?? null;
}
