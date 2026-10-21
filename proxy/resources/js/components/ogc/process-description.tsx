import { ChevronDownIcon, FileTextIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useTranslation } from '@/hooks/use-translation';

export default function ProcessDescription({
    description,
}: {
    description?: string | null;
}) {
    const { t } = useTranslation();

    if (!description) {
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
                <div className="mx-3 border-t py-3">
                    <p className="w-full min-w-0 text-sm leading-relaxed break-words whitespace-pre-wrap text-muted-foreground">
                        {description}
                    </p>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
