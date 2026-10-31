# Event types: release-blocking acceptance audit

The owner's plan for the event-types roadmap (Halloween, Christmas lights, garage or rummage sales) names ten
release-blocking acceptance items and nine risks. This page maps each one to the tests that prove it, as
`file::test` (PHPUnit method, or the node:test / Playwright title as written). Paths are relative to `tests/`.
It was written for 0.14.0-beta.1 by reading every test cited; where an item had no real test, the test was written
then (they are marked **new**). The results of each run are in [test-report.md](test-report.md).

Short forms: `I/` = `Integration/`, `U/` = `Unit/`, `J/` = `js/`, `E/` = `e2e/specs/`.

## 1. Halloween screenshots, flows and tests unchanged

- `I/GoldenApiParityTest.php::testTheDemoCommunityAnswersExactlyLikeTheBaseline` — the demo's `/api/event`, `/api/community`, `/api/homes` and one home keep every 0.9 key, value and order.
- `I/GoldenApiParityTest.php::testTheEventScopedAnswersAreThe011Ones` — the full event-scoped answers, exact nested keys.
- `I/PublicPrivacyTest.php::testOnlyOptedInCurrentSeasonListingsArePublic`, `::testTheNewKeysLeaveThe09KeysAndValuesUnchanged` — exact public home keys in order.
- `U/Listing/ListingDraftParityTest.php::testSixHundredSeededInputsNormalizeExactlyAsIn09` — 600 seeded inputs against the frozen 0.9 `ListingDraft`.
- `J/events.test.mjs` "400 seeded homes: filters, statuses, symbols, tags, pins and cards match 0.9 exactly through the Halloween module" — against the frozen 0.9 browser libraries.
- `E/public.spec.mjs`, `E/claim.spec.mjs`, `E/a11y.spec.mjs`, `E/admin.spec.mjs` — every Halloween flow, unchanged.
- **new** `U/Event/EventNeutralCopyTest.php` — no holiday words outside the type modules, so another type never shows Halloween's words and Halloween's own words stay in its module.

Screenshots are captured by the e2e run but not compared by a test; each release compares them by hand (test report).
Changes to Halloween in 0.14, each a bug fix: a long community or event name gets the compact sidebar brand (the
Ventura example, "Ventura Reserve / Halloween", took three lines); the closing note's double full stop; the 📝 note
marker shown on cards although it is meant for the compact list; cards wider than a 390–430 px phone with larger
text; and three keyboard gaps (pins, the self-entry pin, focus after a My house save). Halloween's filter chips, time
picker and card hearts stay 40 px high (the lights and the sale have 44 px): a known limitation, kept so its pages
stay as they were.

## 2. One address, distinct Halloween, Christmas and rummage listings; the past never public

- `I/GarageSaleSeasonTest.php::testOneAddressListsForHalloweenChristmasAndTheRummageSaleIndependently` — three rows for one address, each with its own note; only the sale is public; last seasons' notes nowhere on the page.
- `E/season.spec.mjs` "an organizer switches the map to the next event; last season disappears and residents list again from an empty form".
- `I/PublicPrivacyTest.php::testNoDraftOrArchivedEventIsEverPublic` — draft and archived names, notes and dates absent from five public URIs.
- `I/EventOccurrencesMigrationTest.php::testTheBackfillKeepsEverySeasonApartAndThePublicMapExactlyAsItWas` — past and future seasons of a 0.10 database stay apart.
- **new** `I/EventTypesAcceptanceTest.php::testChangesToTheActiveListingAndTheRetentionPurgeNeverTouchAnotherEventsListing` — a resident's edit, status and visibility, an organizer's hide and unhide on this season leave last season's row byte-identical; purging the archived season leaves this one alone.

## 3. No unverified ownership; an owner lists for a new event without claiming again

- `I/AuthAndClaimFlowTest.php::testVerifiedClaimPublishesImmediatelyAndPublicApiLeaksNothing`, `::testSecondClaimantLosingAtConfirmationKeepsFirstOwner`; `I/ClaimRaceTest.php::testParallelProcessesProduceExactlyOneOwner`.
- `I/EventsConsoleTest.php::testAnOrganizerPreparesPreviewsAndSwitchesToTheNextEventWithoutCarryingAnythingOver` — the claim stays, "List for" starts empty (no note, no non-food, the new hours), the client cannot name an event, an old listing cannot be revived.
- `I/EventsConsoleTest.php::testAClaimRequestMadeForAnEventThatEndedMeanwhileIsRefused`.
- **new** `I/EventTypesAcceptanceTest.php::testAnotherTypesStatusAndAListForWithoutTheCsrfTokenAreRefused` — "List for" without the CSRF token is refused and stores nothing.

## 4. One active event under concurrency; the preview is not public; archiving hides at once

- `I/EventActivationRaceTest.php::testConcurrentSwitchesToDifferentDraftsHaveExactlyOneWinner`, `::testTheSameDraftActivatedTwiceAtOnceIsActivatedOnce`, `::testTheDatabaseRefusesASecondActiveEvent` (forked processes).
- `I/EventsConsoleTest.php::testOnlyOrganizersReachEventsAndThePreview` — visitors are sent to sign in, residents get 403.
- `I/EventsConsoleTest.php::testEndingTheEventLeavesANeutralMapAndRetentionDeletesItsListings` — after archiving, `/api/homes` is empty and the page is neutral.
- **new** `I/EventTypesAcceptanceTest.php::testChangesToTheActiveListingAndTheRetentionPurgeNeverTouchAnotherEventsListing` — an ended event's home is not readable by id either.

## 5. Christmas lights

- `E/lights.spec.mjs` "never "Lights on" outside a window; New Year's Eve into Jan 1; off tonight; done for the season".
- `E/lights.spec.mjs` "quick filters and the Filters panel: drive-by, no flashing reported, music, walk-up, open tonight, a night, a style".
- `E/lights.spec.mjs` "a resident lists a display: nights, styles, viewing mode, music and station, flashing, treats; My house: off tonight and done for the season".
- `I/HolidayLightsTest.php::testALightsHomeIsPublicWithItsNightsAndExactlyTheEventKeys`, `::testOffTonightIsNamedOnlyOnItsOwnNightAndDoneForTheSeasonNeverShowsADate`, `::testInputIsStrictAndResidentTextIsEscaped` (FM band, a station only with music, nights inside the season).
- `J/holiday-lights.test.mjs` "tags carry words, never only colour, and never promise safety; pins follow music, walk-through, inflatables"; `U/Event/HolidayLightsTypeTest.php` (no copy says "safe").
- **new** `I/EventTypesAcceptanceTest.php::testChangesToTheActiveListingAndTheRetentionPurgeNeverTouchAnotherEventsListing` — a home keeps its own hours (5–9:30 PM, 7–11 PM) on the nights it chose.

Limitation (documented): the lights forms give a home one start and end time for all its nights; the server and
the API take different hours per night (windows), so a later form can offer them without a data change.

## 6. Rummage and garage sales

- `I/GarageSaleTest.php::testASaleHomeIsPublicWithItsDaysAndExactlyTheEventKeys` — different Saturday and Sunday hours, categories, multi-family, payment kinds.
- `I/GarageSaleTest.php::testSoldOutAndPausedHoldForTheirOwnDayAndClosedForTheSale`.
- `I/GarageSaleTest.php::testANoteWithContactDetailsIsRefusedAndNeverStored` — refused on the claim request, in My house and in "List for"; no mail, no claim intent, no listing.
- `I/GarageSaleTest.php::testInputIsStrictAndResidentTextIsEscaped` — only cash, card and apps; only the sale's categories.
- **new** `I/GarageSaleTest.php::testOnlyTheCategoriesTheSaleAllowsHaveFiltersAndTheDayTogglesComeBeforeTheKindOfSale`.
- `U/Shared/ContactDetailsTest.php::testContactDetailsAreFound`, `::testOrdinaryNotesPass` — phone shapes, emails, handles, web addresses found; ordinary notes, now including ranges such as "items 100-1000", pass (D-20261031-33). Numbers written as words are out of scope.
- `E/sale.spec.mjs` "quick filters and the Filters panel: categories, open now, each sale day, multi-family, early birds, search", "open now vs opens at; sold out today vs tomorrow; paused for one day; closed; never "Open now" outside a home's hours", "a resident lists a sale: days with their own hours, categories, payment kinds; a note with contact details is refused and nothing is stored; My house: paused, sold out, closed".

## 7. Map, list, filters and pins agree; every width, theme and appearance; the list without the map

- `E/lights.spec.mjs` and `E/sale.spec.mjs` quick-filter tests (lit pins = cards; the selected panel = its card); `E/public.spec.mjs` "filters affect list and pins together and persist after reload; favorites persist".
- **new** `E/types.spec.mjs` "<type>: no horizontal overflow at <w>px (<scheme>)" — Halloween, lights and sale at 375, 390, 430, 768, 956, 1180 and 1440 px, light and dark, with the Filters panel open.
- **new** `E/types.spec.mjs` "<type>: larger text and more contrast at <w>px: no overflow, 44px targets" (Halloween: no overflow; see item 1) and "<type>: reduced motion: nothing animates, no smooth scrolling".
- `E/lights.spec.mjs`, `E/sale.spec.mjs` "the map failing (tiles or Leaflet blocked) still leaves a readable list"; `E/public.spec.mjs` "map failure still leaves a readable list".
- `E/a11y.spec.mjs` (every Halloween page), the lights and sale specs (map, register sheet, route, About) and **new** `E/types.spec.mjs` "<type>: no WCAG 2.1 AA violations: <page> (<scheme>)" (the lights' and the sale's sign-in, privacy, terms, accessibility and changelog pages; every type's selected home) — axe WCAG 2.1 AA in light and dark on every public page of each type.
- **new** `E/types.spec.mjs` "<type>: keyboard only: filters, list, map pins, selected home, saved stops, route, listing a home and My house".

## 8. Rate limits, proof-of-work, tokens and sign-in unchanged; injection, XSS, IDOR and privacy

- `I/ProofOfWorkAndThrottleTest.php::testSolvedChallengeWorksOnceThenReplayIsRejected`, `::testPerEmailLimitReturns429WithRetryAfterAndSendsNoMail`; `I/AuthAndClaimFlowTest.php::testLinkExpiresAfterFifteenMinutesAndIsSingleUse`; `I/AbuseHardeningTest.php`; `I/SessionIdleTest.php`.
- `I/OwnerAuthorizationTest.php::testCannotEditSomeoneElsesHomeOrSkipCsrf`; `I/HolidayLightsTest.php::testMyHouseNeverReachesAnotherResidentsLightsHome`; `I/GarageSaleTest.php::testMyHouseNeverReachesAnotherResidentsSaleHome`; `I/OwnershipHardeningTest.php::testResidentHomesNeverReachAnotherResidentsHome`.
- `I/HolidayLightsTest.php::testInputIsStrictAndResidentTextIsEscaped`, `I/GarageSaleTest.php::testInputIsStrictAndResidentTextIsEscaped`; `J/lib.test.mjs` "cards and the selected-home panel escape every value taken from the page data, ids included".
- **new** `I/EventTypesAcceptanceTest.php::testOrganizerWrittenEventNamesAndRulesAreEscapedOnEveryPage` — an event name, a rule and quiet hours with markup, escaped on seven pages and the preview.
- **new** `I/EventTypesAcceptanceTest.php::testAnotherTypesStatusAndAListForWithoutTheCsrfTokenAreRefused` — another type's status is refused (transitions stay within the type).
- **new** `J/event-storage.test.mjs` "a hostile shared link changes nothing: markup in e=, stops or start is never a stop, a filter or this event".
- `U/Event/HalloweenTypeTest.php`, `HolidayLightsTypeTest.php`, `GarageSaleTypeTest.php` `::testThePublicProjectionIsStrictAndTyped` — no stored key leaks into the public details.

## 9. No straight lines; without a provider an ordered list and no time; driving only when configured

- `E/lights.spec.mjs` "saved stops belong to this event; without a provider the tour is an ordered list with no lines and no time"; `E/sale.spec.mjs` "saved stops belong to this event; without a provider the route is an ordered list with no lines and no time".
- `I/RoutePlanTest.php::testDisabledByDefaultAndWithoutVerifiedCrossings`, `::testOneGroupOnlyAndCached` (separate walking groups are never joined).
- `I/RouteProfileTest.php::testDrivingNeedsTheTypeTheEventSettingAndAProvider`, `::testDrivingIsRefusedForHalloweenWhateverTheSettingsSay`.
- **new** `I/EventTypesAcceptanceTest.php::testRoutePlansTakeOnlyTheCurrentEventsPublicHomes` — a home listed only last season, an address never listed, an unknown id and a hidden listing are refused.

## 10. Migration, rollback, date boundaries and erasure on a disposable database; images and gates

- `I/EventOccurrencesMigrationTest.php` (all three tests: lossless backfill, down and up, an install without listings); `I/ListingDetailsMigrationTest.php::testTheMigrationGoesDownAndUpAgainAndBackfillsEveryRow`.
- `I/LegacyBridgeTest.php::testEveryFlowKeepsTheLegacyTableEqualToTheActiveEvent` (claim, edit, status, visibility, review, hide, release, erasure), `::testRowsThe09ImageWritesReachTheActiveEvent`.
- `bin/drill-event-upgrade.sh` on the built images: published 0.9.0-beta.1 → backup → this release → image rollback → forward → restore of the pre-upgrade dump (the upgrade production takes: every migration in sequence); `U/Deploy/RollbackDrillTest.php` pins the script. The runbook: [event-types-migration.md](../runbooks/event-types-migration.md).
- Date boundaries: `U/Event/Schedule/DisplayStatusTest.php::testTheSharedCase` and `J/schedule.test.mjs` "every shared display-status case" (48 cases: DST days, Dec 31 → Jan 1, month ends).
- **new** `I/EventTypesAcceptanceTest.php::testReleaseUnclaimAndErasureRemoveEveryEventsListingsWhileATransferKeepsThem`.
- Images, `composer audit`, Playwright: the gates in [test-report.md](test-report.md); Trivy runs in CI on the built images (`U/Deploy/ContainerConfigTest.php::testRuntimeImagesTakeAlpineSecurityFixesAndAreScanned`).

## Risks

| Risk | What guards it |
|---|---|
| Historic overwrite (a season's listing replaced by the next) | listings belong to one event (`UNIQUE (event_id, address_id)`); `I/EventsConsoleTest.php::testAnOrganizerPreparesPreviewsAndSwitchesToTheNextEventWithoutCarryingAnythingOver`, `I/GarageSaleSeasonTest.php::testOneAddressListsForHalloweenChristmasAndTheRummageSaleIndependently`, **new** `I/EventTypesAcceptanceTest.php::testChangesToTheActiveListingAndTheRetentionPurgeNeverTouchAnotherEventsListing` |
| Activation race | advisory lock, the switch names the event it replaces, the partial unique index: `I/EventActivationRaceTest.php` (3 tests) |
| Stale listing revival | an old listing cannot be edited back (`I/EventsConsoleTest.php` first test: PATCH 404), `::testAHomeHiddenLastSeasonStartsHiddenForTheNextEvent`, `I/LegacyBridgeTest.php::testTheAppsOwnMirrorIsNotCopiedBackAndAnotherDaysRowsStayOut`; archived is final (D-20261031-12) |
| Detail JSON leaks | strict projections (`U/Event/*TypeTest.php::testThePublicProjectionIsStrictAndTyped`), exact key lists (`I/HolidayLightsTest.php`, `I/GarageSaleTest.php` first tests, `I/PublicPrivacyTest.php`) |
| Time zones and DST | the shared display-status fixture in PHP and the browser (48 cases); `U/Listing/ListingDraftWindowsTest.php::testADayScopedStatusCarriesTheLocalDate` |
| Release and erasure vs history | `claims` cascade to every event's listings; **new** `I/EventTypesAcceptanceTest.php::testReleaseUnclaimAndErasureRemoveEveryEventsListingsWhileATransferKeepsThem`; the account ledger re-applies erasures after a restore (`I/AccountLedgerTest.php`) |
| Route profile | `I/RouteProfileTest.php` (3 tests), the switch tests in `I/HolidayLightsTest.php` and `I/GarageSaleSeasonTest.php` |
| Rollback from the new schema | `I/LegacyBridgeTest.php`, `I/EventsConsoleTest.php::testAnEventOfATypeThisVersionDoesNotOfferBreaksNothingAndCanBeArchived`, the drill. The previous release sees only a one-day Halloween event; lights and sale listings live only in the event tables (documented) |
| Stale favorites | per-event stores and shared links with `e=`: `J/event-storage.test.mjs` (4 tests), `E/season.spec.mjs` (a link for the ended event shows no stops), the lights and sale saved-stops tests |

## Known limitations at 0.14.0-beta.1

- Halloween's filter chips, time picker and card hearts are 40 px high (44 px on the other types), kept for parity.
- A lights home has one set of hours for all its nights in the forms (the data model takes hours per night).
- The contact check does not read numbers written as words; a range starting at 200 or more ("500-1000") is still refused as a phone number.
- Screenshots are compared by hand, not by a test.
- Emailing residents about a new event, and retiring the legacy `listings` table and its triggers, are later work (ROADMAP).
