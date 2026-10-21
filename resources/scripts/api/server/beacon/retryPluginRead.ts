interface ReadError {
    isAxiosError?: boolean;
    response?: { status?: number; headers?: Record<string, unknown> };
}

const MAX_DELAY = 120000;
const MAX_RETRIES = 3;

const retryDelay = (error: ReadError, attempt: number): number => {
    const header = error.response?.headers?.['retry-after'] ?? error.response?.headers?.['Retry-After'];
    if (typeof header === 'string' || typeof header === 'number') {
        const value = String(header).trim();
        if (/^\d+(?:\.\d+)?$/.test(value)) return Math.max(1000, Number(value) * 1000);

        if (/^[A-Za-z]{3}, /.test(value)) {
            const timestamp = Date.parse(value);
            if (Number.isFinite(timestamp)) return Math.max(1000, timestamp - Date.now());
        }
    }

    return Math.min(5000 * (attempt + 1), 30000);
};

/** Only for idempotent plugin-detail GETs. Never replay an install/update/remove request. */
export const retryPluginRead = async <T>(read: () => Promise<T>): Promise<T> => {
    for (let attempt = 0; ; attempt++) {
        try {
            return await read();
        } catch (error) {
            const failure = error as ReadError | null;
            const status = failure?.response?.status;
            const transient = status === 429 || (status !== undefined && status >= 500);
            const network = failure?.isAxiosError === true && failure.response === undefined;
            if (attempt >= MAX_RETRIES || (!transient && !network)) throw error;

            const delay = retryDelay(failure || {}, attempt);
            // A longer server-requested cooldown requires manual refresh, never an early retry.
            if (delay > MAX_DELAY) throw error;
            await new Promise((resolve) => setTimeout(resolve, delay));
        }
    }
};
