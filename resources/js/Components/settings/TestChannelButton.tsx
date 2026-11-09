import { SendIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Button } from '@euisis/ui';

type Props = {
    onClick: () => void;
    disabled?: boolean;
    processing?: boolean;
    /** Why the test cannot run right now, shown as the button's tooltip. */
    reason?: string;
};

export default function TestChannelButton({ onClick, disabled = false, processing = false, reason }: Props) {
    const { t } = useLocale();

    return (
        <Button type="button" variant="outline" size="sm" onClick={onClick} disabled={disabled} loading={processing} title={reason}
            icon={<SendIcon className="h-3.5 w-3.5" />}>
            {t('settings.testChannel')}
        </Button>
    );
}
