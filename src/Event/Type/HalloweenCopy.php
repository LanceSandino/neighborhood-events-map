<?php

declare(strict_types=1);

namespace App\Event\Type;

/**
 * @module Event/Type/HalloweenCopy
 * @description The Halloween event type's words: default branding, the built-in trick-or-treat reminders
 *     (D-20261019-17: an organizer's own list replaces them) and the plain-text copy the map, the forms, the
 *     About page and the organizer console show for this type. Templates escape every value.
 *
 * @dependencies none
 */
final class HalloweenCopy
{
    public const BRANDING = [
        'subtitle' => 'Your neighborhood. Your Halloween.',
        'brand_emoji' => '🎃',
        'headline' => 'Little ghosts.',
        'headline_accent' => 'Big adventures.',
        'intro' => 'Find the treats, skip the scares, and plan a route that feels right for you and your crew—one sweet stop at a time.',
    ];

    /** Shown on the About page and in the map's guide when an organizer sets no reminders of their own. */
    public const REMINDERS = [
        '💡 Visit homes with a porch light on or someone outside; skip dark houses.',
        '🍬 Take one piece of candy unless a sign or the resident says otherwise.',
        '🎃 Look, don’t touch: decorations, props and yards belong to your neighbors.',
        '🚗 Watch for cars. Cross at corners or marked crossings, never between parked cars.',
        '🚶 Stay on sidewalks and driveways; don’t cut across lawns or gardens.',
        '🔦 Carry a flashlight or glow stick and wear something reflective after dark.',
        '👀 Little ones stay with a grown-up; older kids stay in a group and check in.',
        '🥜 Check treats before eating, especially for allergies. A teal pumpkin (the Teal Pumpkin Project) means the home offers non-food treats.',
        '🐶 Some homes have pets: wait at the door and never open gates.',
        '🙏 Say thank you, be patient with little ones, and be kind to anyone with sensory needs or who isn’t taking part.',
    ];

    public const TEXT = [
        // Public map
        'event_icon' => '🎃',
        'explore_label' => 'Find treat stops',
        'search_placeholder' => 'Search streets, treats, or notes…',
        'list_heading' => '🍬 Treat stops nearby',
        'disclaimer' => 'Listings, hours and sensory details are self-reported by residents and may change.',
        'cta_icon' => '👻',
        'cta_title' => 'Handing out treats?',
        'cta_text' => 'Put your home on the map so the neighborhood can find you!',
        'guide_foot' => 'Something wrong with a listing? Open it on the map and choose “Report this listing”. Sensory labels are self-reported.',
        'house_intro' => 'List a new home, or sign in with a one-time email link to edit hours, mark “out of candy”, or pause your listing.',
        // Listing forms
        'register_title' => 'List your trick-or-treat stop',
        'note_placeholder' => 'e.g. Allergy-friendly bin on the porch (no children’s names)…',
        'status_group' => 'Status tonight',
        // About page
        'about' => 'A neighborhood map of homes handing out treats on Halloween, with sensory notes and a suggested stop order for trick-or-treaters of all ages — families, friends or solo.',
        'reminders_heading' => 'Trick-or-treat reminders',
        // How visitors may reach the homes: the title of the "gated, open during the event" choice.
        'access_gated_open' => 'Gated — open for trick-or-treating',
        // Organizer console
        'admin_access_gated_open' => 'Gated — gates open to trick-or-treaters during event hours',
        'admin_reminders_label' => 'Trick-or-treat reminders (one per line, up to 12)',
        'admin_reminders_help' => 'Shown on the About page and in the map guide. Leave empty for the built-in list (porch lights, one candy, don’t touch decorations, watch for cars…).',
    ];
}
