import { Head } from '@inertiajs/react';
import { CopyCheckIcon } from 'lucide-react';
import { useState } from 'react';

import { ContentTransition } from '@/components/content-transition';
import CacheWarmupPoller from '@/components/ogc/cache-warmup-poller';
import DynamicProcessForm from '@/components/ogc/dynamic-process-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { formatJobDate } from '@/lib/jobs';
import { index } from '@/routes/processes';
import type { OgcCacheStatus, OgcFormSchema, OgcInputPrefill } from '@/types';

export default function ProcessShow({
    formSchema,
    processStatus = 'ready',
    processLastUpdatedAt = null,
    inputPrefill = null,
}: {
    formSchema: OgcFormSchema | null;
    processStatus?: OgcCacheStatus;
    processLastUpdatedAt?: string | null;
    inputPrefill?: OgcInputPrefill | null;
}) {
    const { t, locale } = useTranslation();

    const [retainedSchema, setRetainedSchema] = useState(formSchema);

    if (formSchema !== null && formSchema !== retainedSchema) {
        setRetainedSchema(formSchema);
    }

    const visibleSchema = formSchema ?? retainedSchema;
    const isPreparing = processStatus === 'warming' || formSchema === null;

    if (visibleSchema === null) {
        return (
            <>
                <Head title={t('ogc.processPreparing')} />
                <div className="flex min-w-0 flex-col gap-4 p-4">
                    <ProcessPreparation />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={visibleSchema.title} />

            <div className="flex min-w-0 flex-col gap-4 p-4">
                {isPreparing ? <ProcessPreparation /> : null}
                <div className="page-header flex min-w-0 flex-col gap-2">
                    <h1 className="break-words">{visibleSchema.title}</h1>
                    {processLastUpdatedAt ? (
                        <p className="text-xs text-muted-foreground">
                            {t('ogc.servicesLastUpdatedAt', {
                                date: formatJobDate(
                                    processLastUpdatedAt,
                                    locale,
                                    t('common.notAvailable'),
                                ),
                            })}
                        </p>
                    ) : null}
                </div>

                <ContentTransition default="none" update="form-layout">
                    <div className="flex min-w-0 flex-col gap-4">
                        {inputPrefill ? (
                            <Alert className="border-info-emphasis bg-info/10 text-info-emphasis shadow-xs *:data-[slot=alert-description]:text-info-emphasis/80">
                                <CopyCheckIcon aria-hidden="true" />
                                <AlertTitle>
                                    {t('ogc.inputPrefillTitle', {
                                        source: inputPrefill.sourceJobName,
                                    })}
                                </AlertTitle>
                                <AlertDescription className="flex flex-col gap-1">
                                    <span>
                                        {t('ogc.inputPrefillDescription')}
                                    </span>
                                    {inputPrefill.skippedInputs.length > 0 ? (
                                        <span className="font-medium text-amber-700 dark:text-amber-300">
                                            {t('ogc.inputPrefillSkipped', {
                                                inputs: inputPrefill.skippedInputs.join(
                                                    ', ',
                                                ),
                                            })}
                                        </span>
                                    ) : null}
                                </AlertDescription>
                            </Alert>
                        ) : null}

                        <DynamicProcessForm
                            schema={visibleSchema}
                            inputPrefill={inputPrefill}
                            submissionDisabled={isPreparing}
                        />
                    </div>
                </ContentTransition>
            </div>
        </>
    );
}

function ProcessPreparation() {
    const { t } = useTranslation();

    return (
        <>
            <CacheWarmupPoller
                interval={3000}
                only={[
                    'process',
                    'processStatus',
                    'processLastUpdatedAt',
                    'formSchema',
                    'inputPrefill',
                ]}
            />
            <Alert>
                <Spinner className="text-primary" />
                <AlertTitle>{t('ogc.processPreparingTitle')}</AlertTitle>
                <AlertDescription>
                    {t('ogc.processPreparingDescription')}
                </AlertDescription>
            </Alert>
        </>
    );
}

ProcessShow.layout = {
    breadcrumbs: [
        {
            title: 'Processes',
            titleKey: 'ogc.processesTitle',
            href: index(),
        },
    ],
};
