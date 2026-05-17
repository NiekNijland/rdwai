<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Locale;
use App\Models\User;
use Tests\TestCase;

final class UpdateLocaleControllerTest extends TestCase
{
    public function test_invalid_locale_is_rejected(): void
    {
        $this->from(route('home'))
            ->post(route('locale.update'), ['locale' => 'qq'])
            ->assertSessionHasErrors('locale');
    }

    public function test_missing_locale_is_rejected(): void
    {
        $this->from(route('home'))
            ->post(route('locale.update'), [])
            ->assertSessionHasErrors('locale');
    }

    public function test_guest_locale_change_only_updates_session(): void
    {
        $this->from(route('home'))
            ->post(route('locale.update'), ['locale' => Locale::English->value])
            ->assertRedirect();

        self::assertSame(Locale::English->value, session('locale'));
        self::assertSame(Locale::English->value, app()->getLocale());
    }

    public function test_authenticated_user_locale_change_persists_to_user_row(): void
    {
        $user = User::factory()->createOne([
            'locale' => Locale::Dutch->value,
        ]);

        $this->actingAs($user)
            ->from(route('home'))
            ->post(route('locale.update'), ['locale' => Locale::English->value])
            ->assertRedirect();

        $fresh = $user->fresh();
        self::assertNotNull($fresh);
        self::assertSame(Locale::English, $fresh->locale);
        self::assertSame(Locale::English->value, session('locale'));
    }
}
