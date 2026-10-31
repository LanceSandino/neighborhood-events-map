<?php

declare(strict_types=1);

namespace App\Event;

/**
 * @module Event/ListingTextCheck
 * @description An event type that checks the free text residents publish with a listing (the shared note) beyond
 *     Shared/TextInput's cleaning, e.g. the garage sale's refusal of phone numbers, emails, payment handles and web
 *     addresses (D-20261021-23). Listing/ListingDraft asks it after cleaning; a refused text fails the whole draft
 *     with a field error, so nothing is stored, not even unpublished. Types without it (Halloween, Christmas lights)
 *     keep their notes as before.
 *
 * @dependencies none
 */
interface ListingTextCheck
{
    /** Why the cleaned text of a field ("note") may not be published, or null when it may. */
    public function textProblem(string $field, string $text): ?string;
}
