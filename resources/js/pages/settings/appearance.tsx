import { Head, setLayoutProps } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('pages.settings.appearance.breadcrumb'),
                href: editAppearance(),
            },
        ],
    });

    return (
        <>
            <Head title={t('pages.settings.appearance.title')} />

            <h1 className="sr-only">{t('pages.settings.appearance.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('pages.settings.appearance.heading')}
                    description={t('pages.settings.appearance.description')}
                />
                <AppearanceTabs />
            </div>
        </>
    );
}
