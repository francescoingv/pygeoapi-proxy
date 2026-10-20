import { ChevronDownIcon, FileTextIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
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

export default function ProcessDescription({
    description,
    examples = [],
    selectedExample,
    onExampleChange,
    disabled,
}: {
    description?: string | null;
    examples?: OgcProcessExample[];
    selectedExample: string;
    onExampleChange: (value: string) => void;
    disabled: boolean;
}) {
    const { t } = useTranslation();

    if (!description && examples.length === 0) {
        return null;
    }

    return (
        <Collapsible className="w-full min-w-0 rounded-md border bg-card text-card-foreground transition-colors data-[state=open]:border-primary/25 motion-reduce:transition-none">
            <CollapsibleTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    className="group h-auto w-full justify-between gap-3 px-3 py-2.5 text-left whitespace-normal"
                >
                    <span className="flex min-w-0 items-center gap-2">
                        <FileTextIcon
                            aria-hidden="true"
                            data-icon="inline-start"
                            className="text-primary"
                        />
                        {t('ogc.serviceDescription')}
                    </span>
                    <ChevronDownIcon
                        aria-hidden="true"
                        data-icon="inline-end"
                        className="text-muted-foreground transition-transform duration-200 group-data-[state=open]:rotate-180 motion-reduce:transition-none"
                    />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="mx-3 flex min-w-0 flex-col gap-4 border-t py-3">
                    {description ? (
                        <p className="w-full min-w-0 text-sm leading-relaxed break-words whitespace-pre-wrap text-muted-foreground">
                            {description}
                        </p>
                    ) : null}
                    {examples.length > 0 ? (
                        <Field className="min-w-0 sm:max-w-md">
                            <FieldLabel htmlFor="process-example">
                                {t('ogc.serviceExample')}
                            </FieldLabel>
                            <Select
                                value={selectedExample}
                                onValueChange={onExampleChange}
                                disabled={disabled}
                            >
                                <SelectTrigger
                                    id="process-example"
                                    className="w-full min-w-0"
                                    aria-describedby="process-example-description"
                                >
                                    <SelectValue
                                        placeholder={t('ogc.chooseExample')}
                                    >
                                        {
                                            examples.find(
                                                (example) =>
                                                    example.id ===
                                                    selectedExample,
                                            )?.title
                                        }
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent className="max-w-[calc(100vw-2rem)] sm:max-w-lg">
                                    <SelectGroup>
                                        {examples.map((example) => (
                                            <SelectItem
                                                key={example.id}
                                                value={example.id}
                                                textValue={example.title}
                                            >
                                                <span className="flex min-w-0 flex-col gap-1">
                                                    <span className="font-normal">
                                                        {example.title}
                                                    </span>
                                                    {example.description ? (
                                                        <span className="text-xs whitespace-normal text-muted-foreground italic">
                                                            {
                                                                example.description
                                                            }
                                                        </span>
                                                    ) : null}
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <FieldDescription id="process-example-description">
                                {t('ogc.serviceExampleDescription')}
                            </FieldDescription>
                        </Field>
                    ) : null}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
