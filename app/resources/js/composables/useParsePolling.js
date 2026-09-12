import { onUnmounted } from 'vue';

/**
 * Автоопрос статуса парсинга, пока он активен (queued/running).
 * Останавливается на терминальном статусе и при уходе со страницы.
 */
export function useParsePolling(store, options = {}) {
    const baseInterval = options.interval ?? 3000;
    const slowInterval = options.slowInterval ?? 10000;
    const queueHintAfter = options.queueHintAfter ?? 120000;

    let timer = null;
    let failures = 0;
    let startedAt = null;

    function currentInterval() {
        return failures >= 3 ? slowInterval : baseInterval;
    }

    function schedule() {
        stop();
        timer = setTimeout(tick, currentInterval());
    }

    async function tick() {
        timer = null;

        if (!store.isParsing) {
            return;
        }

        const ok = await store.fetchStatus();

        if (!store.isParsing) {
            options.onTerminal?.();
            return;
        }

        if (!ok) {
            failures += 1;
        } else {
            failures = 0;
        }

        if (startedAt && Date.now() - startedAt > queueHintAfter) {
            options.onStaleQueue?.();
        }

        schedule();
    }

    function start() {
        if (timer !== null) {
            return;
        }

        failures = 0;
        startedAt = Date.now();
        schedule();
    }

    function stop() {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    onUnmounted(stop);

    return { start, stop };
}
