import { FileInputIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldDescription } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import type { OgcProcessExample } from '@/types';

export default function ProcessExamples({
    examples = [],
    selectedExample,
    onExampleChange,
    onLoadExample,
    disabled,
}: {
    examples?: OgcProcessExample[];
    selectedExample: string;
    onExampleChange: (value: string) => void;
    onLoadExample: (value: string) => void;
    disabled: boolean;
}) {
    const { t } = useTranslation();
    const example = examples.find((example) => example.id === selectedExample);

    if (examples.length === 0) {
        return null;
    }

    return (
        <Card
            className="min-w-0"
            role="region"
            aria-labelledby="process-examples-title"
        >
            <CardHeader>
                <CardTitle id="process-examples-title">
                    {t('ogc.serviceExample')}
                </CardTitle>
                <CardDescription id="process-examples-help">
                    {t('ogc.serviceExampleDescription')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Field className="min-w-0">
                    <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                        <Select
                            value={selectedExample}
                            onValueChange={onExampleChange}
                            disabled={disabled}
                        >
                            <SelectTrigger
                                id="process-example"
                                className="w-full min-w-0 sm:max-w-md"
                                aria-labelledby="process-examples-title"
                                aria-describedby={
                                    example?.description
                                        ? 'process-examples-help process-example-description'
                                        : 'process-examples-help'
                                }
                            >
                                <SelectValue
                                    placeholder={t('ogc.chooseExample')}
                                >
                                    {example?.title}
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent className="max-w-[calc(100vw-2rem)] sm:max-w-lg">
                                <SelectGroup>
                                    {examples.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={item.id}
                                            textValue={item.title}
                                        >
                                            {item.title}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <Button
                            type="button"
                            variant="outline"
                            className="shrink-0"
                            disabled={disabled || !example}
                            onClick={() => {
                                if (example) {
                                    onLoadExample(example.id);
                                }
                            }}
                        >
                            <FileInputIcon
                                aria-hidden="true"
                                data-icon="inline-start"
                            />
                            {t('ogc.loadInputParameters')}
                        </Button>
                    </div>
                    {example?.description ? (
                        <FieldDescription
                            id="process-example-description"
                            className="whitespace-pre-wrap italic"
                            aria-live="polite"
                        >
                            {example.description}
                        </FieldDescription>
                    ) : null}
                </Field>
            </CardContent>
        </Card>
    );
}
