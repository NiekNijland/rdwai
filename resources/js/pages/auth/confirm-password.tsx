import { Form, Head, setLayoutProps } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const { t } = useTranslation();

    setLayoutProps({
        title: t('pages.auth.confirmPassword.heading'),
        description: t('pages.auth.confirmPassword.description'),
    });

    return (
        <>
            <Head title={t('pages.auth.confirmPassword.title')} />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {t('pages.auth.confirmPassword.password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder={t(
                                    'pages.auth.confirmPassword.passwordPlaceholder',
                                )}
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                {t('pages.auth.confirmPassword.submit')}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}
