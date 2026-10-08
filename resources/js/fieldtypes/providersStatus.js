import * as defaultApi from '../api.js';

// One row per provider, in the order the server sends them. The test is offered when a key is set, or when the fake
// engine answers for every provider.
export function providerRows(meta) {
    const fakeEngine = meta?.fake_engine === true;
    const providers = Array.isArray(meta?.providers) ? meta.providers : [];

    return providers.map((provider) => {
        const configured = provider.configured === true;

        return {
            provider: provider.provider,
            label: provider.label,
            badge: providerBadge(configured),
            source: sourceNote(provider.source),
            hint: configured ? null : setupHint(provider.env),
            testable: configured || fakeEngine,
        };
    });
}

// Whether the provider has an API key, as a badge.
export function providerBadge(configured) {
    return configured ? { text: __('Configured'), color: 'green' } : { text: __('No API key'), color: 'default' };
}

function sourceNote(source) {
    return (
        {
            ai: __('The key comes from config/ai.php, which Tolk shares with the rest of the site.'),
            addon: __("The key is Tolk's own, from the addon's config."),
        }[source] ?? null
    );
}

function setupHint(env) {
    if (!env?.ai) {
        return null;
    }

    return __('Add :variable to the .env file, or :override to give Tolk a key of its own.', {
        variable: env.ai,
        override: env.addon,
    });
}

// What a test's answer says, as a line of text: a success names the model and the time, a failure is the server's
// message.
export function describeResult(result) {
    if (result?.ok !== true) {
        return { ok: false, text: result?.error || __('The test failed.') };
    }

    const replacements = { model: result.label || result.model, ms: result.latency_ms };

    return {
        ok: true,
        text: result.fake
            ? __('The fake engine answered for :model in :ms ms, without calling the provider.', replacements)
            : __(':model answered in :ms ms.', replacements),
    };
}

// The server answers a provider's failure with `ok: false`; a refused request (no key, no permission) is an error.
export async function testConnection(provider, api = defaultApi) {
    try {
        return await api.post('providers/test', { provider });
    } catch (error) {
        return { ok: false, error: error.message };
    }
}
