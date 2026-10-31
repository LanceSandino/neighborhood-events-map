<?php

declare(strict_types=1);

namespace App\Event\Type;

/**
 * @module Event/Type/HolidayLightsCopy
 * @description The Christmas lights event type's words: default branding (midnight blue and gold), the built-in
 *     viewing etiquette (D-20261019-17: an organizer's own list replaces it), the display styles and viewing modes
 *     with their icons, and the plain-text copy the map, the forms, the About page and the organizer console show
 *     for this type. "No flashing reported" is the only sensory claim, never "safe". Templates escape every value.
 *
 * @dependencies none
 */
final class HolidayLightsCopy
{
    public const BRANDING = [
        'subtitle' => 'Your neighborhood. Your holiday lights.',
        'brand_emoji' => '✨',
        'headline' => 'Holiday nights.',
        'headline_accent' => 'Bright lights.',
        'intro' => 'Find festive displays, discover quiet viewing options and plan an evening route at your own pace.',
        'primary_color' => '#38597F',
        'accent_color' => '#CE9D3D',
    ];

    /** Display styles in canonical order: [label, icon]. Icons are decorative and never animated. */
    public const STYLES = [
        'classic' => ['Classic', '✨'],
        'animated' => ['Animated', '🌟'],
        'synchronized_music' => ['Light show to music', '🎵'],
        'inflatables' => ['Inflatables', '⛄'],
        'walkthrough' => ['Walk-through', '🎄'],
    ];

    /** Viewing modes: [label, icon, explanation]. */
    public const VIEWING = [
        'drive_by_only' => ['Drive-by', '🚗', 'Drive-by only: please enjoy it from the street or your car, and do not walk up or knock.'],
        'walk_up_welcome' => ['Walk-up', '🚶', 'Walk-up welcome: visitors may come up the sidewalk for a closer look.'],
    ];

    /** Shown on the About page and in the map's guide when an organizer sets no etiquette of their own. */
    public const REMINDERS = [
        '🚗 Park only where it is legal and never block driveways, mailboxes or fire hydrants.',
        '🌙 Keep voices, engines and car audio low, especially during quiet hours.',
        '🚫 Do not knock on or walk up to “Drive-by” homes; enjoy them from the street.',
        '🚶 Stay on sidewalks and the street: yards, porches and decorations belong to your neighbors.',
        '⚡ Flashing warnings and “No flashing reported” are self-reported by residents and not checked by anyone. Take care if flashing light affects you.',
        '🔦 Walking? Wear something reflective, carry a light and cross at corners.',
        '📻 A display’s music plays on its FM station in your car; keep the volume down outside.',
        '🙏 Be patient in traffic, thank your neighbors and leave every display as you found it.',
    ];

    public const TEXT = [
        // Public map
        'event_icon' => '✨',
        'explore_label' => 'Find light displays',
        'search_placeholder' => 'Search addresses or display types…',
        'list_heading' => '✨ Homes lighting up',
        'disclaimer' => 'Nights, hours and display details are self-reported by residents and may change. “No flashing reported” means the resident reported no flashing effects; it is not a safety promise.',
        'cta_icon' => '🎄',
        'cta_title' => 'Putting up lights?',
        'cta_text' => 'Let your neighbors find your home, with a quick, private email sign-in.',
        'guide_foot' => 'Something wrong with a listing? Open it on the map and choose “Report this listing”. Flashing warnings are self-reported.',
        'house_intro' => 'List a new home, or sign in with a one-time email link to change your nights and hours, mark “Off tonight”, or say you are done for the season.',
        'most_homes' => 'Most homes',
        'times_vary' => 'Times vary by house',
        'season_before' => 'Season preview',
        'season_during' => 'Tonight',
        'season_after' => 'Season over',
        'best_night' => 'Best night for a neighborhood walk',
        'route_title' => '✨ Your lights tour',
        'route_rules_title' => 'Before you go',
        'night_label' => 'Night',
        'style_label' => 'Display style',
        // Listing forms
        'register_title' => 'List your light display',
        'note_placeholder' => 'e.g. Best seen from the corner; music starts at 7 PM (no names or contact details)…',
        'status_group' => 'Lights',
        'drive_by_help' => 'Drive-by only means please do not approach: visitors enjoy your display from the street or their car and do not walk up or knock.',
        'flashing_help' => 'Tick this if your display flashes, strobes or blinks quickly. Visitors who avoid flashing light can then skip it; nobody checks this, so please be honest.',
        'publish_help' => 'Listing publishes your street address, nights, hours and these details on the map. Your email is never shown.',
        // About page
        'about' => 'A neighborhood map of homes with holiday light displays: their nights, hours and viewing notes, and a suggested viewing order for walking or a drive-by tour.',
        'reminders_heading' => 'Viewing etiquette',
        // How visitors may reach the homes: the title of the "gated, open during the event" choice.
        'access_gated_open' => 'Gated — open for light viewing',
        // Server-rendered pages only (page_* keys are not in the public event): the address example in "List your
        // home", the accessibility statement's pin icons and filters, and the terms' self-reported details.
        'page_address_example' => '123 Main St',
        'page_a11y_icons' => '✨ lights, 🎵 music, ⛄ inflatables',
        'page_a11y_filters' => 'Filters for drive-by homes and “No flashing reported” help plan a calm tour; nothing on the page flashes or moves.',
        'page_terms_details' => 'display details and flashing reports',
        'page_terms_check' => '“No flashing reported”, music and the other labels are only what residents tell us; it is not a safety promise.',
        // Organizer console
        'admin_access_gated_open' => 'Gated — gates open to visitors during viewing hours',
        'admin_reminders_label' => 'Viewing etiquette (one per line, up to 12)',
        'admin_reminders_help' => 'Shown on the About page and in the map guide. Leave empty for the built-in list (legal parking, quiet hours, don’t block driveways, don’t knock on drive-by homes, flashing warnings are self-reported…).',
    ];
}
