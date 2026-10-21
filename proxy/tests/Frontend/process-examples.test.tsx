import * as inertia from '@inertiajs/react';
import { expect, mock, spyOn, test } from 'bun:test';
import { Children, isValidElement } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

import DynamicProcessForm from '../../resources/js/components/ogc/dynamic-process-form';
import * as noteEditor from '../../resources/js/components/ogc/job-note-editor';
import * as processExamples from '../../resources/js/components/ogc/process-examples';
import * as button from '../../resources/js/components/ui/button';
import * as select from '../../resources/js/components/ui/select';
import { TooltipProvider } from '../../resources/js/components/ui/tooltip';
import * as validation from '../../resources/js/hooks/use-ogc-form-validation';
import type { OgcFormSchema } from '../../resources/js/types';

test('service examples replace compatible inputs only when loaded and preserve job metadata and outputs', () => {
    const schema: OgcFormSchema = {
        id: 'example',
        title: 'Example service',
        description: 'Service description text',
        fields: {
            count: {
                name: 'count',
                title: 'Count',
                kind: 'scalar',
                type: 'integer',
            },
            optional: { name: 'optional', title: 'Optional', kind: 'scalar' },
        },
        outputs: {},
        inputValidationSchema: {},
        jobControlOptions: [],
        outputTransmission: [],
        examples: [
            {
                id: 'payload_1',
                title: 'Simple payload example N°1',
                description: 'A simple execution payload.',
                inputs: { count: { value: 7 }, unknown: 42 },
            },
            {
                id: 'payload_2',
                title: 'Simple payload example N°2',
                description: 'A simple alternative execution payload.',
                inputs: { count: 0 },
            },
        ],
    };
    const note = { type: 'doc', content: [] };
    const outputs = { result: { selected: true, format: null } };
    let data: any = {
        name: 'My run',
        inputs: { count: 99, optional: 12 },
        note,
        outputs,
    };
    let chooseExample: ((value: string) => void) | undefined;
    let loadExample: ((value: string) => void) | undefined;
    const form = spyOn(inertia, 'useForm').mockReturnValue({
        data,
        setData: (update: (current: typeof data) => typeof data) => {
            data = update(data);
        },
        processing: false,
        errors: {},
        clearErrors() {},
    } as any);
    const valuesReplaced = mock(() => {});
    const validationHook = spyOn(
        validation,
        'useOgcFormValidation',
    ).mockReturnValue({
        errors: {},
        validLabel: '',
        stateFor: () => 'neutral',
        handleFormChange() {},
        valuesReplaced,
    } as any);
    const editor = spyOn(noteEditor, 'JobNoteEditor').mockImplementation(
        () => null,
    );
    const originalExamples = processExamples.default;
    const examples = spyOn(processExamples, 'default').mockImplementation(
        (props) => {
            loadExample = props.onLoadExample;

            return originalExamples(props);
        },
    );
    const originalSelect = select.Select;
    const selector = spyOn(select, 'Select').mockImplementation((props) => {
        chooseExample = props.onValueChange;

        return originalSelect(props);
    });

    try {
        const html = renderToStaticMarkup(
            <TooltipProvider>
                <DynamicProcessForm schema={schema} />
            </TooltipProvider>,
        );
        expect(chooseExample).toBeFunction();
        expect(loadExample).toBeFunction();
        expect(html).toContain('role="combobox"');
        expect(html).not.toContain('Service description text');
        expect(html.indexOf('Service description')).toBeLessThan(
            html.indexOf('role="combobox"'),
        );
        expect(html.indexOf('role="combobox"')).toBeLessThan(
            html.indexOf('id="process-name"'),
        );
        expect(data.inputs).toEqual({ count: 99, optional: 12 });

        chooseExample!('payload_1');
        expect(data.inputs).toEqual({ count: 99, optional: 12 });
        expect(valuesReplaced).not.toHaveBeenCalled();
        loadExample!('payload_1');
        expect(data).toEqual({
            name: 'My run',
            inputs: { count: 7 },
            note,
            outputs,
        });
        expect(valuesReplaced).toHaveBeenCalledWith('inputs');

        chooseExample!('payload_2');
        expect(data.inputs).toEqual({ count: 7 });
        expect(valuesReplaced).toHaveBeenCalledTimes(1);
        loadExample!('payload_2');
        expect(data).toEqual({
            name: 'My run',
            inputs: { count: 0 },
            note,
            outputs,
        });
        expect(valuesReplaced).toHaveBeenCalledTimes(2);

        loadExample!('unknown');
        expect(data.inputs).toEqual({ count: 0 });
        expect(valuesReplaced).toHaveBeenCalledTimes(2);

        renderToStaticMarkup(
            <TooltipProvider>
                <DynamicProcessForm schema={schema} submissionDisabled />
            </TooltipProvider>,
        );
        loadExample!('payload_1');
        expect(data.inputs).toEqual({ count: 0 });
        expect(valuesReplaced).toHaveBeenCalledTimes(2);

        chooseExample = undefined;
        const emptyHtml = renderToStaticMarkup(
            <TooltipProvider>
                <DynamicProcessForm schema={{ ...schema, examples: [] }} />
            </TooltipProvider>,
        );
        expect(chooseExample).toBeUndefined();
        expect(emptyHtml).not.toContain('role="combobox"');
    } finally {
        form.mockRestore();
        validationHook.mockRestore();
        editor.mockRestore();
        examples.mockRestore();
        selector.mockRestore();
    }
});

test('the selected example description is previewed and its inputs load only from the button', () => {
    const examples = [
        {
            id: 'payload_1',
            title: 'First example title',
            description: 'First example description',
            inputs: { count: 1 },
        },
        {
            id: 'payload_2',
            title: 'Second example title',
            description: 'Second example description',
            inputs: { count: 2 },
        },
    ];
    const loadExample = mock(() => {});
    let loadButton: { disabled?: boolean; onClick?: unknown } | undefined;
    const originalButton = button.Button;
    const buttonSpy = spyOn(button, 'Button').mockImplementation((props) => {
        loadButton = props;

        return originalButton(props);
    });

    const render = (selectedExample: string, disabled = false) =>
        renderToStaticMarkup(
            <processExamples.default
                examples={examples}
                selectedExample={selectedExample}
                onExampleChange={() => {}}
                onLoadExample={loadExample}
                disabled={disabled}
            />,
        );

    try {
        const initialHtml = render('');
        expect(loadButton?.disabled).toBe(true);
        expect(initialHtml).not.toContain('id="process-example-description"');

        const firstHtml = render('payload_1');
        expect(firstHtml).toContain('First example title');
        expect(firstHtml).toContain('First example description');
        expect(firstHtml).not.toContain('Second example description');

        const secondHtml = render('payload_2');
        expect(secondHtml).toContain('Second example title');
        expect(secondHtml).toContain('Second example description');
        expect(secondHtml).not.toContain('First example description');
        expect(secondHtml.indexOf('role="combobox"')).toBeLessThan(
            secondHtml.indexOf('id="process-example-description"'),
        );
        expect(loadExample).not.toHaveBeenCalled();
        expect(loadButton?.disabled).toBe(false);
        (loadButton?.onClick as () => void)();
        expect(loadExample).toHaveBeenCalledTimes(1);
        expect(loadExample).toHaveBeenCalledWith('payload_2');

        render('payload_2', true);
        expect(loadButton?.disabled).toBe(true);
    } finally {
        buttonSpy.mockRestore();
    }
});

test('confirming clear fields resets only inputs without reusing prefilled inputs', () => {
    const schema: OgcFormSchema = {
        id: 'example',
        title: 'Example service',
        fields: {
            count: {
                name: 'count',
                title: 'Count',
                kind: 'scalar',
                type: 'integer',
            },
            mode: {
                name: 'mode',
                title: 'Mode',
                kind: 'enum',
                options: ['fixed'],
            },
        },
        outputs: { result: { name: 'result', title: 'Result', formats: [] } },
        inputValidationSchema: {},
        jobControlOptions: [],
        outputTransmission: [],
    };
    const originalData = {
        name: 'My run',
        inputs: { count: 17 },
        note: { type: 'doc' },
        outputs: { result: { selected: false, format: null } },
    };
    let data: unknown = originalData;
    let renderedForm: ReactElement;
    const clearErrors = mock(() => {});
    const resetPathPrefix = mock(() => {});
    const form = spyOn(inertia, 'useForm').mockReturnValue({
        data,
        setData: (update: unknown) => {
            data = typeof update === 'function' ? update(data) : update;
        },
        processing: false,
        errors: {},
        clearErrors,
    } as any);
    const validationHook = spyOn(
        validation,
        'useOgcFormValidation',
    ).mockReturnValue({
        errors: {},
        validLabel: '',
        stateFor: () => 'neutral',
        handleFormChange() {},
        resetPathPrefix,
    } as any);
    const editor = spyOn(noteEditor, 'JobNoteEditor').mockImplementation(
        () => null,
    );

    function Capture() {
        renderedForm = DynamicProcessForm({
            schema,
            inputPrefill: {
                sourceJobId: 1,
                sourceJobName: 'Earlier run',
                inputs: { count: 88 },
                skippedInputs: [],
            },
        });

        return renderedForm;
    }

    function findConfirmation(node: ReactNode): (() => void) | undefined {
        for (const child of Children.toArray(node)) {
            if (
                !isValidElement<{
                    children?: ReactNode;
                    onConfirm?: () => void;
                }>(child)
            ) {
                continue;
            }

            const confirm =
                child.props.onConfirm ?? findConfirmation(child.props.children);

            if (confirm) {
                return confirm;
            }
        }
    }

    try {
        renderToStaticMarkup(
            <TooltipProvider>
                <Capture />
            </TooltipProvider>,
        );
        const confirm = findConfirmation(renderedForm!);
        expect(confirm).toBeFunction();
        expect(data).toBe(originalData);
        confirm!();
        expect(data).toEqual({
            name: 'My run',
            inputs: { mode: 'fixed' },
            note: { type: 'doc' },
            outputs: { result: { selected: false, format: null } },
        });
        expect(clearErrors).not.toHaveBeenCalled();
        expect(resetPathPrefix).toHaveBeenCalledTimes(1);
        expect(resetPathPrefix).toHaveBeenCalledWith('inputs');
    } finally {
        form.mockRestore();
        validationHook.mockRestore();
        editor.mockRestore();
    }
});
