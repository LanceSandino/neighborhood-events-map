<?php

declare(strict_types=1);

namespace App\Event\Type;

/**
 * @module Event/Type/GarageSaleCopy
 * @description The garage sale event type's words: default branding (teal and coral), the built-in sale etiquette
 *     (D-20261019-17: an organizer's own list replaces it), the item categories and payment kinds with their icons,
 *     the field errors of the contact-detail check, and the plain-text copy the map, the forms, the About page and
 *     the organizer console show for this type. No prices, photos, contact details or commerce. Templates escape
 *     every value.
 *
 * @dependencies none
 */
final class GarageSaleCopy
{
    public const BRANDING = [
        'subtitle' => 'Your neighborhood. Your garage sale.',
        'brand_emoji' => '🏷️',
        'headline' => 'Good finds.',
        'headline_accent' => 'Close to home.',
        'intro' => 'Discover neighbors selling furniture, toys, tools and more. Browse categories and build a simple shopping route.',
        'primary_color' => '#178D80',
        'accent_color' => '#E77C5D',
    ];

    /** Item categories in canonical order: [label, icon]. Icons are decorative; the words always show. */
    public const CATEGORIES = [
        'kids_baby' => ['Kids & baby', '🍼'],
        'toys' => ['Toys', '🧸'],
        'furniture' => ['Furniture', '🪑'],
        'clothing' => ['Clothing', '👕'],
        'tools' => ['Tools', '🛠️'],
        'books_media' => ['Books & media', '📚'],
        'electronics' => ['Electronics', '🔌'],
        'sports' => ['Sports', '⚽'],
        'household' => ['Household', '🧺'],
        'free' => ['Free items', '🆓'],
    ];

    /** Payment kinds: [label, icon]. The kind only, never a username, handle or phone number. */
    public const PAYMENT_TYPES = ['cash' => ['Cash', '💵'], 'card' => ['Card', '💳'], 'apps' => ['Payment apps', '📱']];

    /** The field error for each kind of contact detail Shared/ContactDetails finds in a note. */
    public const CONTACT_ERRORS = [
        'phone' => 'Please remove the phone number: notes are public and never carry contact details.',
        'email' => 'Please remove the email address: notes are public and never carry contact details.',
        'handle' => 'Please remove the payment username or handle: choose the payment kinds instead (never a username or phone number).',
        'url' => 'Please remove the web address: notes are public and never link to other sites.',
    ];

    /**
     * Shown on the About page and in the map's guide when an organizer sets no etiquette of their own. The first three
     * (the map's Rules sheet and the route show those) cover early birds, parking and traffic, driveways and private
     * yards, recalled or prohibited items and that the map sells nothing.
     */
    public const REMINDERS = [
        '🐦 Respect each home’s start time: no early birds before the hours on the map or the organizer’s early-bird time.',
        '🚗 Park legally and keep the street clear for traffic; never block driveways or mailboxes, and stay on driveways and in garages — yards and houses are private.',
        '🚫 No recalled, unsafe or prohibited items (weapons, medicines, expired car seats). This map sells nothing: prices and payment stay between you and the seller.',
        '🧾 Use “Report this listing” for prohibited items or a listing that looks like a business.',
        '🙏 Be kind to neighbors who are not selling: keep voices low and take your trash with you.',
    ];

    public const TEXT = [
        // Public map
        'event_icon' => '🏷️',
        'explore_label' => 'Find sales',
        'search_placeholder' => 'Search streets, categories or notes…',
        'list_heading' => '🏷️ Sales around you',
        'disclaimer' => 'Sale days, hours, categories and notes are self-reported by residents and may change. Prices and payment are between you and the seller; this map sells nothing.',
        'cta_icon' => '🏷️',
        'cta_title' => 'Having a sale?',
        'cta_text' => 'Let your neighbors find your home, with a quick, private email sign-in.',
        'guide_foot' => 'Something wrong with a listing, such as a business or prohibited items? Open it on the map and choose “Report this listing”.',
        'house_intro' => 'List a new home, or sign in with a one-time email link to change your days and hours, pause for today, say you are sold out today, or close your sale.',
        'most_homes' => 'Most homes',
        'own_schedule' => 'each home sets its own sale days and hours',
        'times_vary' => 'Individual hours vary',
        // The season of the sale's first day fills %s (Twig season_of: spring, summer, fall, winter; by hemisphere).
        'season_before' => 'Coming this %s',
        'season_during' => 'Today',
        'season_between' => 'More sale days ahead',
        'season_after' => 'Sale over',
        'route_title' => '🏷️ Your sale stops',
        'route_rules_title' => 'Before you go',
        'early_bird_rule' => 'Early birds: not before',
        'days_label' => 'Sale days',
        'categories_label' => 'Categories',
        // Listing forms
        'register_title' => 'List your sale',
        'note_placeholder' => 'e.g. Baby gear in the garage, books on the porch (no phone numbers, emails, usernames or links)…',
        'status_group' => 'Sale',
        'days_help' => 'Pick the sale days you take part in and your hours on each; Saturday and Sunday may differ.',
        'payment_help' => 'The kinds only — never a username or phone number.',
        'early_birds_help' => 'Tick this if shoppers may come before your start time.',
        'note_help' => 'Notes are public. Phone numbers, emails, payment usernames and web addresses are refused.',
        'publish_help' => 'Listing publishes your street address, sale days, hours, categories and these details on the map. Your email is never shown.',
        // About page
        'about' => 'A neighborhood map of homes holding a garage sale: their days, hours and categories, and a suggested order of stops for walking or driving. No prices, photos or payments.',
        'reminders_heading' => 'Sale etiquette',
        // How visitors may reach the homes: the title of the "gated, open during the event" choice.
        'access_gated_open' => 'Gated — open for the sale',
        // Server-rendered pages only (page_* keys are not in the public event): the address example in "List your
        // home", the accessibility statement's pin icons and filters, and the terms' self-reported details.
        'page_address_example' => '123 Main St',
        'page_a11y_icons' => '🧸 toys, 🪑 furniture, 🛠️ tools',
        'page_a11y_filters' => 'Filters by category, sale day and opening hours help plan a short route.',
        'page_terms_details' => 'categories, payment kinds and notes',
        'page_terms_check' => 'Check items yourself before you buy — categories and the other labels are only what residents tell us, and this map sells nothing.',
        // Organizer console
        'admin_access_gated_open' => 'Gated — gates open to shoppers during sale hours',
        'admin_reminders_label' => 'Sale etiquette (one per line, up to 12)',
        'admin_reminders_help' => 'Shown on the About page and in the map guide. Leave empty for the built-in list (early birds, parking and driveways, private yards, no recalled or prohibited items, no in-app commerce…).',
    ];
}
