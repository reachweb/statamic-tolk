// Loads the Overview's cells, at most `concurrency` at a time: a cell calls `scan` until its cursor is null, then the next
// cell in the queue starts. Loading again (Count again) drops the queue; a cell whose request is already on its way
// stops after it, unreported, and holds its place until then.

export const CONCURRENCY = 3;

export function createCellLoader({ scan, onChange = () => {}, concurrency = CONCURRENCY }) {
    let queue = [];
    let active = 0;
    let generation = 0;

    function current(job) {
        return job.generation === generation;
    }

    function pump() {
        while (active < concurrency && queue.length > 0) {
            load(queue.shift());
        }
    }

    async function load(job) {
        active++;
        onChange(job.cell.key, { status: 'loading' });

        try {
            let cursor = null;

            do {
                const answer = await scan({ ...job.cell, cursor, fresh: job.fresh });

                if (!current(job)) {
                    return;
                }

                cursor = answer.cursor ?? null;
                onChange(job.cell.key, { ...answer, status: cursor === null ? 'done' : 'loading' });
            } while (cursor !== null);
        } catch (error) {
            if (current(job)) {
                onChange(job.cell.key, { status: 'failed', error: error?.message ?? String(error) });
            }
        } finally {
            active--;
            pump();
        }
    }

    function enqueue(cells, fresh) {
        for (const cell of cells) {
            queue.push({ cell, fresh, generation });
            onChange(cell.key, { status: 'waiting' });
        }

        pump();
    }

    return {
        // `cells`: `{ key, type, container, site }` each. `fresh` counts again instead of taking cached cells.
        load(cells, { fresh = false } = {}) {
            generation++;
            queue = [];
            enqueue(cells, fresh);
        },

        // Queues one cell again, after its request failed.
        retry(cell) {
            enqueue([cell], false);
        },

        stop() {
            generation++;
            queue = [];
        },

        get active() {
            return active;
        },
    };
}
