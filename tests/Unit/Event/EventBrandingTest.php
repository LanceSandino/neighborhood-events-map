<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\EventBranding;
use App\Event\EventTypeRegistry;
use App\Event\Type\GarageSaleType;
use App\Event\Type\HalloweenType;
use App\Event\Type\HolidayLightsType;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/EventBrandingTest
 * @description Per-event branding (ADR 0012): what an organizer may set for one event (emoji, headline and its accent
 *   part, intro, the two brand colours and the event's About text, every field optional), how it is checked (strict
 *   keys, lengths refused rather than cut, #RRGGBB colours, empty means the type's default), what visitors get (the
 *   event's own values over its type's defaults) and how the branding a community kept before 0.16 becomes an event's
 *   (only custom values move; a built-in default of any type is left to the event's own type).
 * @dependencies Event/EventBranding, Event/EventTypeRegistry, Event/Type/*
 */
final class EventBrandingTest extends TestCase
{
    public function testAnOrganizerSetsAnyFieldAndEmptyMeansTheTypeDefault(): void
    {
        $e = [];
        $b = EventBranding::normalize(['intro' => '  Lights on every street.  ', 'primary_color' => '#38597f', 'headline' => '', 'accent_color' => null,
            'about' => "First paragraph.\r\n\r\n\r\nSecond.", 'brand_emoji' => '🎄'], $e);
        self::assertSame([], $e);
        self::assertSame(['brand_emoji' => '🎄', 'intro' => 'Lights on every street.', 'primary_color' => '#38597F', 'about' => "First paragraph.\n\nSecond."], $b,
            'canonical order; empty and null fields are left out (the type default applies); colours upper case');
        self::assertSame([], EventBranding::normalize([], $e));
        self::assertSame([], EventBranding::normalize(null, $e), 'no branding at all is fine');
        self::assertSame([], $e);
    }

    public function testUnknownFieldsBadColoursAndTooLongTextAreRefusedNotCut(): void
    {
        $e = [];
        EventBranding::normalize(['headline' => str_repeat('a', 41), 'intro' => str_repeat('b', 281), 'primary_color' => 'orange', 'accent_color' => '#12345',
            'brand_emoji' => '🎃🎃🎃🎃🎃🎃🎃🎃🎃', 'about' => str_repeat('c', 1501), 'logo' => 'x.svg', 'subtitle' => 'Ours'], $e);
        self::assertSame([
            'settings.branding.headline' => 'Keep the headline to 40 characters.',
            'settings.branding.intro' => 'Keep the intro to 280 characters.',
            'settings.branding.primary_color' => 'Use a colour like #38597F, or leave it empty for the default.',
            'settings.branding.accent_color' => 'Use a colour like #38597F, or leave it empty for the default.',
            'settings.branding.brand_emoji' => 'Keep the emoji to 8 characters.',
            'settings.branding.about' => 'Keep the About text to 1500 characters.',
            'settings.branding.logo' => 'Unknown field.',
            'settings.branding.subtitle' => 'Unknown field.',
        ], $e, 'the logo and the subtitle belong to the community, not to an event');
        $e = [];
        EventBranding::normalize(['headline', 'intro'], $e);
        self::assertSame(['settings.branding' => 'Send the branding as an object.'], $e);
        $e = [];
        EventBranding::normalize(['headline' => ['x'], 'intro' => 12], $e);
        self::assertSame(['settings.branding.headline' => 'Send text.', 'settings.branding.intro' => 'Send text.'], $e);
    }

    public function testVisitorsSeeTheEventsOwnValuesOverItsTypesDefaults(): void
    {
        $lights = new HolidayLightsType();
        self::assertSame(['brand_emoji' => '✨', 'headline' => 'Holiday nights.', 'headline_accent' => 'Bright lights.',
            'intro' => 'Find festive displays, discover quiet viewing options and plan an evening route at your own pace.', 'primary_color' => '#38597F', 'accent_color' => '#CE9D3D'],
            EventBranding::look($lights, []));
        self::assertSame(['brand_emoji' => '🌟', 'headline' => 'Holiday nights.', 'headline_accent' => 'On Holly Drive.',
            'intro' => 'Find festive displays, discover quiet viewing options and plan an evening route at your own pace.', 'primary_color' => '#38597F', 'accent_color' => '#AA3344'],
            EventBranding::look($lights, ['brand_emoji' => '🌟', 'headline_accent' => 'On Holly Drive.', 'accent_color' => '#AA3344', 'about' => 'Our lights.']));
        self::assertSame(['#F07936', '#524077'], array_values(array_slice(EventBranding::look(new HalloweenType(), []), 4)), 'a type without its own colours: the community defaults');
        self::assertSame('Our lights.', EventBranding::about(['about' => 'Our lights.']));
        self::assertSame('', EventBranding::about([]));
    }

    public function testTheBrandingACommunityKeptBeforeMovesOnlyWhereItIsCustom(): void
    {
        $types = EventTypeRegistry::builtIn();
        $legacy = ['logo' => 'logo.svg', 'brand_emoji' => '🎃', 'headline' => '👻 Tiny ghosts.', 'headline_accent' => 'Big adventures.',
            'intro' => 'Find festive displays, discover quiet viewing options and plan an evening route at your own pace.',
            'primary_color' => '#d9652b', 'accent_color' => '#524077', 'show_locality' => true];
        self::assertSame(['headline' => '👻 Tiny ghosts.', 'primary_color' => '#D9652B', 'about' => 'Our night.'],
            EventBranding::fromLegacy($legacy, 'Our night.', $types),
            'defaults of any type (Halloween\'s emoji, accent part and plum, the lights\' intro) take the event\'s own type default; the logo and the city stay with the community');
        self::assertSame([], EventBranding::fromLegacy(['headline' => ''] + (new GarageSaleType())->defaultBranding(), '', $types),
            'an empty text and the sale\'s own defaults: nothing to move (the subtitle is not an event field)');
    }
}
