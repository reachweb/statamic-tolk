import { describe, expect, it } from 'vitest';
import { createCellLoader } from './cells.js';

const cell = (key) => {
    const [type, container, site] = key.split('|');

    return { key, type, container, site };
};

// A scan that answers when the test says so: `next()` resolves the oldest open request with `{ cursor }`.
function controlledScan() {
    const open = [];
    const calls = [];

    return {
        calls,
        open,
        scan(request) {
            calls.push(request);

            return new Promise((resolve, reject) => open.push({ request, resolve, reject }));
        },
        async answer(index, cursor = null, extra = {}) {
            const [request] = open.splice(index, 1);
            request.resolve({ counts: { missing: 1 }, cursor, ...extra });
            await flush();
        },
        async fail(index, message) {
            const [request] = open.splice(index, 1);
            request.reject(new Error(message));
            await flush();
        },
    };
}

async function flush() {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
}

function loader(scan, concurrency) {
    const states = {};
    const history = [];
    const instance = createCellLoader({
        scan: scan.scan,
        concurrency,
        onChange: (key, state) => {
            states[key] = state;
            history.push([key, state.status]);
        },
    });

    return { instance, states, history };
}

const CELLS = ['entry|pages|el', 'entry|pages|de', 'term|topics|el', 'globals|site_settings|el', 'nav|main|el'].map(cell);

describe('the cell loader', () => {
    it('scans at most three cells at a time, each until its cursor is null, then the next', async () => {
        const scan = controlledScan();
        const { instance, states } = loader(scan);

        instance.load(CELLS);

        expect(scan.calls.map((call) => [call.type, call.container, call.site, call.cursor, call.fresh])).toEqual([
            ['entry', 'pages', 'el', null, false],
            ['entry', 'pages', 'de', null, false],
            ['term', 'topics', 'el', null, false],
        ]);
        expect(Object.values(states).map((state) => state.status)).toEqual(['loading', 'loading', 'loading', 'waiting', 'waiting']);
        expect(instance.active).toBe(3);

        // The first cell continues from its cursor; nothing else starts meanwhile.
        await scan.answer(0, '7', { scanned: 7 });
        expect(states['entry|pages|el']).toMatchObject({ status: 'loading', cursor: '7', scanned: 7 });
        expect(scan.calls).toHaveLength(4);
        expect(scan.calls[3]).toMatchObject({ key: 'entry|pages|el', cursor: '7' });

        // The second cell finishes, so the fourth starts.
        await scan.answer(0);
        expect(states['entry|pages|de']).toMatchObject({ status: 'done', cursor: null, counts: { missing: 1 } });
        expect(scan.calls[4]).toMatchObject({ key: 'globals|site_settings|el', cursor: null });
        expect(scan.open).toHaveLength(3);

        while (scan.open.length > 0) {
            await scan.answer(0);
        }

        expect(Object.values(states).every((state) => state.status === 'done')).toBe(true);
        expect(scan.calls).toHaveLength(6);
        expect(instance.active).toBe(0);
    });

    it('marks a cell whose request failed, goes on with the others, and retries it on request', async () => {
        const scan = controlledScan();
        const { instance, states } = loader(scan, 1);

        instance.load(CELLS.slice(0, 2));
        await scan.fail(0, 'The server could not be reached.');

        expect(states['entry|pages|el']).toEqual({ status: 'failed', error: 'The server could not be reached.' });
        expect(scan.calls[1]).toMatchObject({ key: 'entry|pages|de' });

        instance.retry(CELLS[0]);
        expect(states['entry|pages|el']).toEqual({ status: 'waiting' });

        await scan.answer(0);
        await scan.answer(0);

        expect(states['entry|pages|el'].status).toBe('done');
        expect(scan.calls.map((call) => call.key)).toEqual(['entry|pages|el', 'entry|pages|de', 'entry|pages|el']);
    });

    it('starts over fresh when loaded again, without reporting what the earlier load still had on its way', async () => {
        const scan = controlledScan();
        const { instance, states } = loader(scan, 2);

        instance.load(CELLS.slice(0, 3));
        instance.load(CELLS.slice(0, 3), { fresh: true });

        // The two earlier requests still hold their places, so nothing new starts yet.
        expect(scan.calls).toHaveLength(2);
        expect(Object.values(states).map((state) => state.status)).toEqual(['waiting', 'waiting', 'waiting']);

        await scan.answer(0, '3');
        expect(states['entry|pages|el']).toEqual({ status: 'loading' });
        expect(scan.calls[2]).toMatchObject({ key: 'entry|pages|el', cursor: null, fresh: true });

        await scan.answer(0);
        await scan.answer(0);
        await scan.answer(0);
        await scan.answer(0);

        expect(Object.values(states).map((state) => state.status)).toEqual(['done', 'done', 'done']);
        expect(scan.calls.slice(2).map((call) => [call.key, call.fresh])).toEqual([
            ['entry|pages|el', true],
            ['entry|pages|de', true],
            ['term|topics|el', true],
        ]);
    });

    it('reports nothing more once stopped', async () => {
        const scan = controlledScan();
        const { instance, history } = loader(scan);

        instance.load(CELLS.slice(0, 1));
        instance.stop();
        await scan.answer(0, '2');

        expect(history).toEqual([
            ['entry|pages|el', 'waiting'],
            ['entry|pages|el', 'loading'],
        ]);
        expect(scan.calls).toHaveLength(1);
        expect(instance.active).toBe(0);
    });
});
