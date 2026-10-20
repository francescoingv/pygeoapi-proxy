import { EraserIcon, TriangleAlertIcon } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';

export default function ClearProcessFormButton({
    onConfirm,
    disabled,
}: {
    onConfirm: () => void;
    disabled: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    disabled={disabled}
                >
                    <EraserIcon data-icon="inline-start" />
                    {t('ogc.clearFields')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <TriangleAlertIcon
                            aria-hidden="true"
                            className="size-5 text-destructive"
                        />
                        {t('ogc.clearFieldsTitle')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('ogc.clearFieldsDescription')}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={disabled}
                        onClick={() => {
                            onConfirm();
                            setOpen(false);
                        }}
                    >
                        <EraserIcon data-icon="inline-start" />
                        {t('ogc.clearFields')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
