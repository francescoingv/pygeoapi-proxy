import { router } from '@inertiajs/react';
import { expect, mock, spyOn, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';

import {
    initializeLanguage,
    useLanguage,
} from '../../resources/js/hooks/use-language';

function browser(stored = 'en', cookie = 'en') {
    const originalWindow = globalThis.window;
    const originalDocument = globalThis.document;
    const storage = new Map([['language', stored]]);
    const document = {
        cookie: `language=${cookie}`,
        documentElement: { lang: cookie },
    };
    Object.assign(globalThis, {
        window: {
            location: { href: 'https://example.test/processes/example' },
            localStorage: {
                getItem: (key: string) => storage.get(key) ?? null,
                setItem: (key: string, value: string) =>
                    storage.set(key, value),
            },
        },
        document,
    });

    let navigate: (event: any) => void = () => {};
    let success: (event: any) => void = () => {};
    const stop = mock(() => {});
    const on = spyOn(router, 'on').mockImplementation((name, callback) => {
        if (name === 'navigate') {
            navigate = callback;
        }

        if (name === 'success') {
            success = callback;
        }

        return stop;
    });
    const reload = spyOn(router, 'reload').mockImplementation(() => {});
    const flush = spyOn(router, 'flushAll').mockImplementation(() => {});
    initializeLanguage();

    let updateLanguage: ReturnType<typeof useLanguage>['updateLanguage'];
    function LanguageControl() {
        updateLanguage = useLanguage().updateLanguage;

        return null;
    }
    renderToStaticMarkup(<LanguageControl />);

    return {
        document,
        storage,
        reload,
        flush,
        update: (language: 'it' | 'en') => updateLanguage(language),
        navigate: (language: 'it' | 'en', url?: string) => {
            if (url) {
                window.location.href = url;
            }

            navigate({ detail: { page: { props: { language } } } });
        },
        success: (language: 'it' | 'en') =>
            success({ detail: { page: { props: { language } } } }),
        restore() {
            Object.assign(globalThis, {
                window: originalWindow,
                document: originalDocument,
            });
            initializeLanguage();
            on.mockRestore();
            reload.mockRestore();
            flush.mockRestore();
        },
    };
}

test('changing language updates browser preference and refreshes server props without losing state or scroll', () => {
    const app = browser();

    try {
        app.update('it');

        expect(app.storage.get('language')).toBe('it');
        expect(app.document.cookie).toContain('language=it;');
        expect(app.document.documentElement.lang).toBe('it');
        expect(app.flush).toHaveBeenCalledTimes(1);
        expect(app.reload).toHaveBeenCalledTimes(1);
        expect(app.reload.mock.calls[0][0]).not.toHaveProperty('only');
        app.update('it');
        expect(app.reload).toHaveBeenCalledTimes(1);
    } finally {
        app.restore();
    }
});

test('initial server props are refreshed when localStorage has a different language than the request cookie', () => {
    const app = browser('it', 'en');

    try {
        app.navigate('en');
        expect(app.reload).toHaveBeenCalledTimes(1);
        app.reload.mock.calls[0][0]!.onSuccess!({
            props: { language: 'it' },
        } as any);
        app.navigate('it');
        expect(app.reload).toHaveBeenCalledTimes(1);
    } finally {
        app.restore();
    }
});

test('history pages in the old language refresh once while a matching page is left alone', () => {
    const app = browser('it', 'it');

    try {
        app.navigate('it');
        expect(app.reload).not.toHaveBeenCalled();
        app.navigate('en');
        app.navigate('en');
        expect(app.reload).toHaveBeenCalledTimes(1);
        expect(app.flush).toHaveBeenCalledTimes(1);
    } finally {
        app.restore();
    }
});

test('rapid switches cancel the previous language request and ignore its late completion', () => {
    const app = browser();

    try {
        app.update('it');
        const first = app.reload.mock.calls[0][0]!;
        const cancel = mock(() => {});
        first.onCancelToken!({ cancel });
        app.update('en');
        expect(cancel).toHaveBeenCalledTimes(1);
        expect(app.reload).toHaveBeenCalledTimes(2);
        first.onSuccess!({ props: { language: 'it' } } as any);
        expect(app.reload).toHaveBeenCalledTimes(2);
        app.reload.mock.calls[1][0]!.onSuccess!({
            props: { language: 'en' },
        } as any);
        app.navigate('en');
        expect(app.reload).toHaveBeenCalledTimes(2);
        expect(app.storage.get('language')).toBe('en');
    } finally {
        app.restore();
    }
});

test('a late poll in the old language triggers another refresh after the language request finishes', () => {
    const app = browser();

    try {
        app.update('it');
        app.success('en');
        expect(app.reload).toHaveBeenCalledTimes(1);
        app.reload.mock.calls[0][0]!.onSuccess!({
            props: { language: 'it' },
        } as any);
        app.success('en');
        expect(app.reload).toHaveBeenCalledTimes(2);
        app.success('it');
        expect(app.storage.get('language')).toBe('it');
    } finally {
        app.restore();
    }
});

test('history navigation to another URL refreshes it even when a language refresh is pending', () => {
    const app = browser('it', 'it');

    try {
        app.navigate('en');
        app.navigate('en', 'https://example.test/processes/another');
        expect(app.reload).toHaveBeenCalledTimes(2);
    } finally {
        app.restore();
    }
});

test('an unexpected unchanged server language does not cause an endless reload loop', () => {
    const app = browser('it', 'en');

    try {
        app.navigate('en');
        app.success('en');
        app.reload.mock.calls[0][0]!.onSuccess!({
            props: { language: 'en' },
        } as any);
        expect(app.reload).toHaveBeenCalledTimes(1);
    } finally {
        app.restore();
    }
});
