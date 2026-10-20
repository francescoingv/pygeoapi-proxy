import * as inertia from '@inertiajs/react';
import { expect, mock, spyOn, test } from 'bun:test';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { flushSync } from 'react-dom';
import { createRoot } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { toast } from 'sonner';

import * as transitions from '../../resources/js/components/content-transition';
import * as dynamicForm from '../../resources/js/components/ogc/dynamic-process-form';
import * as noteEditor from '../../resources/js/components/ogc/job-note-editor';
import { TooltipProvider } from '../../resources/js/components/ui/tooltip';
import * as validation from '../../resources/js/hooks/use-ogc-form-validation';
import ProcessShow from '../../resources/js/pages/processes/show';
import type { OgcFormSchema } from '../../resources/js/types';

const schema: OgcFormSchema = {
    id: 'example',
    title: 'Example service',
    fields: {},
    outputs: {},
    inputValidationSchema: {},
    jobControlOptions: [],
    outputTransmission: [],
};

// Only the DOM operations used by React for this page are needed here.
class TestNode {
    nodeType = 1;
    namespaceURI = 'http://www.w3.org/1999/xhtml';
    nodeName: string;
    tagName: string;
    parentNode: TestNode | null = null;
    children: TestNode[] = [];
    attributes = new Map<string, string>();
    style = {};
    value = '';
    ownerDocument: any;
    text = '';

    constructor(name: string, document: any) {
        this.nodeName = this.tagName = name.toUpperCase();
        this.ownerDocument = document;
    }
    appendChild(child: TestNode) {
        child.parentNode?.removeChild(child);
        this.children.push(child);
        child.parentNode = this;

        return child;
    }
    insertBefore(child: TestNode, sibling: TestNode) {
        child.parentNode?.removeChild(child);
        this.children.splice(this.children.indexOf(sibling), 0, child);
        child.parentNode = this;

        return child;
    }
    removeChild(child: TestNode) {
        this.children.splice(this.children.indexOf(child), 1);
        child.parentNode = null;

        return child;
    }
    setAttribute(name: string, value: string) {
        this.attributes.set(name, String(value));
    }
    removeAttribute(name: string) {
        this.attributes.delete(name);
    }
    addEventListener() {}
    removeEventListener() {}
    get firstChild() {
        return this.children[0] ?? null;
    }
    get textContent(): string {
        return (
            this.text + this.children.map((child) => child.textContent).join('')
        );
    }
    set textContent(text: string) {
        this.text = text;
        this.children = [];
    }
    find(name: string): TestNode | undefined {
        return this.tagName === name.toUpperCase()
            ? this
            : this.children.map((child) => child.find(name)).find(Boolean);
    }
}

function mountPage() {
    const oldWindow = globalThis.window;
    const oldDocument = globalThis.document;
    const document: any = {
        addEventListener() {},
        removeEventListener() {},
        activeElement: null,
    };
    document.createElement = (name: string) => new TestNode(name, document);
    document.createElementNS = (_namespace: string, name: string) =>
        new TestNode(name, document);
    document.createTextNode = (text: string) => {
        const node = new TestNode('#text', document);
        node.nodeType = 3;
        node.text = text;

        return node;
    };
    const window = {
        document,
        HTMLIFrameElement: class {},
        getComputedStyle: () => ({}),
    };
    document.defaultView = window;
    Object.assign(globalThis, { window, document });
    const container = new TestNode('div', document);
    const root = createRoot(container as unknown as HTMLElement);

    return {
        container,
        render: (page: ReactElement) => flushSync(() => root.render(page)),
        async restore() {
            flushSync(() => root.unmount());
            await new Promise((resolve) => setImmediate(resolve));
            Object.assign(globalThis, {
                window: oldWindow,
                document: oldDocument,
            });
        },
    };
}

test('warm language cache keeps the same dirty form and file mounted until translated schema arrives', async () => {
    const head = spyOn(inertia, 'Head').mockImplementation(() => null);
    const poll = spyOn(inertia, 'usePoll').mockImplementation(() => ({
        start() {},
        stop() {},
    }));
    const transition = spyOn(
        transitions,
        'ContentTransition',
    ).mockImplementation(({ children }) => <>{children}</>);
    const file = new File(['input'], 'input.csv');
    const dirty = {
        name: 'My run',
        inputs: { count: 17, file },
        outputs: { result: 'csv' },
        note: { type: 'doc' },
    };
    let edit: (value: typeof dirty) => void = () => {};
    let current: typeof dirty | null = null;
    let disabled: boolean | undefined;
    const form = spyOn(dynamicForm, 'default').mockImplementation((props) => {
        const [data, setData] = useState<typeof dirty | null>(null);
        edit = setData;
        current = data;
        disabled = (props as any).submissionDisabled;

        return (
            <form>
                <input type="file" />
                <span>{props.schema.title}</span>
            </form>
        );
    });
    const page = mountPage();

    try {
        page.render(<ProcessShow formSchema={schema} />);
        flushSync(() => edit(dirty));
        const originalForm = page.container.find('form');
        const originalFile = page.container.find('input');

        page.render(<ProcessShow formSchema={null} processStatus="warming" />);
        expect(page.container.find('form')).toBe(originalForm);
        expect(page.container.find('input')).toBe(originalFile);
        expect(current).toBe(dirty);
        expect(current!.inputs.file).toBe(file);
        expect(disabled).toBe(true);
        expect(page.container.textContent).toContain('being prepared');
        expect(poll).toHaveBeenCalled();

        page.render(
            <ProcessShow
                formSchema={{ ...schema, title: 'Servizio di esempio' }}
            />,
        );
        expect(page.container.find('form')).toBe(originalForm);
        expect(current).toBe(dirty);
        expect(disabled).toBe(false);
        expect(page.container.textContent).toContain('Servizio di esempio');
    } finally {
        await page.restore();
        head.mockRestore();
        poll.mockRestore();
        transition.mockRestore();
        form.mockRestore();
    }
});

test('a cold process page shows preparation without mounting a form', () => {
    const head = spyOn(inertia, 'Head').mockImplementation(() => null);
    const poll = spyOn(inertia, 'usePoll').mockImplementation(() => ({
        start() {},
        stop() {},
    }));

    try {
        const html = renderToStaticMarkup(
            <ProcessShow formSchema={null} processStatus="warming" />,
        );
        expect(html).toContain('being prepared');
        expect(html).not.toContain('<form');
    } finally {
        head.mockRestore();
        poll.mockRestore();
    }
});

test('disabled process submission blocks keyboard and programmatic submit before validation', () => {
    const validateForm = mock(() => false);
    const validationHook = spyOn(
        validation,
        'useOgcFormValidation',
    ).mockReturnValue({
        errors: {},
        validLabel: '',
        stateFor: () => 'neutral',
        validateForm,
        handleFormChange() {},
    } as any);
    const note = spyOn(noteEditor, 'JobNoteEditor').mockImplementation(
        () => null,
    );
    const errorToast = spyOn(toast, 'error').mockImplementation(() => 'toast');
    let form: ReactElement<any>;
    function Capture() {
        form = dynamicForm.default({ schema, submissionDisabled: true } as any);

        return form;
    }

    try {
        const html = renderToStaticMarkup(
            <TooltipProvider>
                <Capture />
            </TooltipProvider>,
        );
        const preventDefault = mock(() => {});
        form!.props.onSubmit({ preventDefault });
        expect(preventDefault).toHaveBeenCalled();
        expect(validateForm).not.toHaveBeenCalled();
        expect(html).toMatch(/<button[^>]*type="submit"[^>]*disabled=""/);
        expect(errorToast).not.toHaveBeenCalled();
    } finally {
        validationHook.mockRestore();
        note.mockRestore();
        errorToast.mockRestore();
    }
});
