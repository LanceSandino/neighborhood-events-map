# Event types: release-blocking acceptance audit

The owner's plan for the event-types roadmap (Halloween, Christmas lights, garage or rummage sales) names ten
release-blocking acceptance items and nine risks. This page maps each one to the tests that prove it, as
`file::test` (PHPUnit method, or the node:test / Playwright title as written). Paths are relative to `tests/`.
It was written for 0.14.0-beta.1 by reading every test cited; where an item had no real test, the test was written
then (they are marked **new**). 0.15.0-beta.1 changed one product decision — several events may be published at once
([ADR 0011](../adr/0011-several-published-events.md)) — so every item that said "one active event" now reads "one
default event, at most one current event per type, and the state rules", and the tests that proved it were rewritten
for the new flows (marked **0.15**; the section [Several events](#several-events-015) adds the new items). The results
of each run are in [test-report.md](test-report.md).

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
- **0.15** `E/season.spec.mjs` "an organizer closes the season; last season disappears, next year is created and residents list again from an empty form" (then Christmas lights and a rummage sale open next to it: three independent listings).
- `I/PublicPrivacyTest.php::testNoDraftOrArchivedEventIsEverPublic` — draft and archived names, notes and dates absent from five public URIs.
- `I/EventOccurrencesMigrationTest.php::testTheBackfillKeepsEverySeasonApartAndThePublicMapExactlyAsItWas` — past and future seasons of a 0.10 database stay apart.
- **new** `I/EventTypesAcceptanceTest.php::testChangesToTheActiveListingAndTheRetentionPurgeNeverTouchAnotherEventsListing` — a resident's edit, status and visibility, an organizer's hide and unhide on this season leave last season's row byte-identical; purging the archived season leaves this one alone.

## 3. No unverified ownership; an owner lists for a new event without claiming again

- `I/AuthAndClaimFlowTest.php::testVerifiedClaimPublishesImmediatelyAndPublicApiLeaksNothing`, `::testSecondClaimantLosingAtConfirmationKeepsFirstOwner`; `I/ClaimRaceTest.php::testParallelProcessesProduceExactlyOneOwner`.
- **0.15** `I/EventsConsoleTest.php::testAnOrganizerClosesTheSeasonNextYearIsCreatedAndNothingCarriesOver` — the claim stays, "List for" next year starts empty (no note, no non-food, the event's hours), the event is named only by `e=` (an `event_id` field is refused), a closed event takes no listing and its listing is read-only, an old listing cannot be revived.
- `I/EventsConsoleTest.php::testAClaimRequestMadeForAnEventThatEndedMeanwhileIsRefused`.
- **new** `I/EventTypesAcceptanceTest.php::testAnotherTypesStatusAndAListForWithoutTheCsrfTokenAreRefused` — "List for" without the CSRF token is refused and stores nothing.

## 4. One default event, at most one current event per type, the state rules under concurrency; the preview is not public; closing and archiving hide at once

(0.11–0.14 read "one active event under concurrency"; superseded by D-20261031-37.)

- **0.15** `I/EventLifecycleRaceTest.php::testConcurrentOpensOfEventsOfOneTypeHaveExactlyOneWinner`, `::testTheSameEventClosedThreeTimesAtOnceIsClosedOnceWithOneNextYear`, `::testConcurrentDefaultChangesLeaveExactlyOneDefault`, `::testTheDatabaseRefusesASecondCurrentEventOfAType`, `::testTheDatabaseRefusesASecondDefaultAndADefaultThatIsNotPublic` (forked processes; replaces `EventActivationRaceTest`).
- `I/EventsConsoleTest.php::testOnlyOrganizersReachEventsAndThePreview` — visitors are sent to sign in, residents get 403 (announce, open, default, close), organizers need the CSRF token.
- `I/EventsConsoleTest.php::testEndingTheEventLeavesANeutralMapAndRetentionDeletesItsListings` — after archiving the only event, `/api/homes` is empty and the page is neutral.
- **0.15** `I/EventsConsoleTest.php::testAnOrganizerClosesTheSeasonNextYearIsCreatedAndNothingCarriesOver` — closing hides the homes at once (by id too), shows the thank-you message, creates next year's upcoming event once; a closed event never reopens; a draft of a kind that is current cannot open.
- **0.15** `I/MultiEventTest.php::testTheDefaultEventIsWhatSlashShowsAndPassesOnWhenArchived` — a draft is never the default; an archived default passes to the newest open event, else the newest public one.
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
- `I/LegacyBridgeTest.php::testEveryFlowKeepsTheLegacyTableEqualToTheActiveEvent` (claim, edit, status, visibility, review, hide, release, erasure), `::testRowsThe09ImageWritesReachTheActiveEvent`; **0.15** `::testTheLegacyTableMirrorsTheNewestOpenOrClosedHalloweenNeverAnUpcomingOne`.
- **0.15** `I/MultiEventMigrationTest.php::testA014DatabaseKeepsEveryEventAndListingAndItsOpenEventBecomesTheDefault` (down and up again), `::testA09DatabaseUpgradesThroughEveryMigrationLosslessly` (production's path: 0.9 keys and values kept).
- `bin/drill-event-upgrade.sh` on the built images: published 0.9.0-beta.1 → backup → this release → (0.15) close the Halloween, list for next year → image rollback → forward → restore of the pre-upgrade dump (the upgrade production takes: every migration in sequence); `U/Deploy/RollbackDrillTest.php` pins the script. The runbook: [event-types-migration.md](../runbooks/event-types-migration.md).
- Date boundaries: `U/Event/Schedule/DisplayStatusTest.php::testTheSharedCase` and `J/schedule.test.mjs` "every shared display-status case" (48 cases: DST days, Dec 31 → Jan 1, month ends).
- **new** `I/EventTypesAcceptanceTest.php::testReleaseUnclaimAndErasureRemoveEveryEventsListingsWhileATransferKeepsThem`.
- Images, `composer audit`, Playwright: the gates in [test-report.md](test-report.md); Trivy runs in CI on the built images (`U/Deploy/ContainerConfigTest.php::testRuntimeImagesTakeAlpineSecurityFixesAndAreScanned`).

## Risks

| Risk | What guards it |
|---|---|
| Historic overwrite (a season's listing replaced by the next) | listings belong to one event (`UNIQUE (event_id, address_id)`); `I/EventsConsoleTest.php::testAnOrganizerClosesTheSeasonNextYearIsCreatedAndNothingCarriesOver`, `I/GarageSaleSeasonTest.php::testOneAddressListsForHalloweenChristmasAndTheRummageSaleIndependently`, **new** `I/EventTypesAcceptanceTest.php::testChangesToTheActiveListingAndTheRetentionPurgeNeverTouchAnotherEventsListing` |
| Activation race (0.15: open, close and default races; 0.17: hidden events) | the occurrences lock, the partial unique indexes (one current per type, hidden events included; one default) and the check: `I/EventLifecycleRaceTest.php` (9 tests) |
| Stale listing revival | an old listing cannot be edited back (`I/EventsConsoleTest.php` first test: PATCH 404; a closed event's listing is read-only, 403), `::testAHomeHiddenLastSeasonStartsHiddenForTheNextEvent`, `I/LegacyBridgeTest.php::testTheAppsOwnMirrorIsNotCopiedBackAndAnotherDaysRowsStayOut`; closed never reopens, archived is final (D-20261031-37); "Use last year's details" only fills the form (`I/MultiEventTest.php::testUseLastYearsDetailsCopiesOnlyTheResidentsOwnPreviousListingOfTheSameHomeAndType`) |
| Detail JSON leaks | strict projections (`U/Event/*TypeTest.php::testThePublicProjectionIsStrictAndTyped`), exact key lists (`I/HolidayLightsTest.php`, `I/GarageSaleTest.php` first tests, `I/PublicPrivacyTest.php`) |
| Time zones and DST | the shared display-status fixture in PHP and the browser (48 cases); `U/Listing/ListingDraftWindowsTest.php::testADayScopedStatusCarriesTheLocalDate` |
| Release and erasure vs history | `claims` cascade to every event's listings; **new** `I/EventTypesAcceptanceTest.php::testReleaseUnclaimAndErasureRemoveEveryEventsListingsWhileATransferKeepsThem`; the account ledger re-applies erasures after a restore (`I/AccountLedgerTest.php`) |
| Route profile | `I/RouteProfileTest.php` (3 tests), the switch tests in `I/HolidayLightsTest.php` and `I/GarageSaleSeasonTest.php` |
| Rollback from the new schema | `I/LegacyBridgeTest.php`, `I/EventsConsoleTest.php::testAnEventOfATypeThisVersionDoesNotOfferBreaksNothingAndCanBeArchived`, `I/MultiEventMigrationTest.php`, the drill. The 0.9 release sees only the newest open or closed one-day Halloween; upcoming events, lights and sale listings live only in the event tables; the 0.10–0.14 images are reached by restore (documented, D-20261031-41) |
| Stale favorites | per-event stores and shared links with `e=`: `J/event-storage.test.mjs` (4 tests), `E/season.spec.mjs` (a link for the ended event shows no stops), the lights and sale saved-stops tests |

## Several events (0.15)

- One default event, at most one current event per type, the state rules: item 4 above.
- Close → next year's dates for each type (October 31 rollover, November 1 – January 1 across the year, a sale to be announced): `U/Event/NextEventTest.php::testClosingGivesNextYearsTentativeDates`, `::testANewEventStartsOnItsTypesUsualDates`, `::testTheThankYouTextIsTheOrganizersOrTheTypesDefaultWithTheYearAndNextYearsDates`; `I/MultiEventTest.php::testClosingEachTypeCreatesNextYearsEventWithWhatLasts` (what carries over; a sale to be announced cannot open).
- Public visibility per state (no homes for upcoming, closed, draft, archived; no draft or archived in `/api/events`; `e=` naming one is 404 for the API and the default event for a page): `I/MultiEventTest.php::testEachStateShowsVisitorsOnlyWhatItMay`; `E/events.spec.mjs` "the switcher: the default event at "/", every public event by mouse and keyboard, its own theme, the closed message and the upcoming cards".
- Listing creation only for upcoming or open events with dates; the event of another type or a draft refused (IDOR): `I/MultiEventTest.php::testListingsGoToTheNamedUpcomingOrOpenEventOnly`.
- "Use last year's details" only from the resident's own previous listing of the same home and type: `I/MultiEventTest.php::testUseLastYearsDetailsCopiesOnlyTheResidentsOwnPreviousListingOfTheSameHomeAndType`; `E/events.spec.mjs` "a resident switches events in My house and lists next year's Halloween with "Use last year's details"".
- The bridge rule: `I/LegacyBridgeTest.php::testTheLegacyTableMirrorsTheNewestOpenOrClosedHalloweenNeverAnUpcomingOne`; the drill (step 4b).
- Migration from the 0.9 schema and from 0.14 data: `I/MultiEventMigrationTest.php` (2 tests); `I/EventOccurrencesMigrationTest.php` (the 0.10 → latest chain, the default set).
- Organizer close → next year created → default change: `E/events.spec.mjs` "an organizer opens and closes an event (next year's is created) and changes the default", `E/season.spec.mjs`.
- Saved stops per event, the switcher at 375–1440 px, axe in light and dark: `E/events.spec.mjs` "each event keeps its own saved stops; a shared link keeps its event", "the switcher and the event pages: no overflow from 375 to 1440 px, axe in light and dark".
- The fictional demo with every public state: `I/MultiEventTest.php::testTheFictionalDemoSeedsEveryPublicState`.
- Halloween parity with several events possible: a one-event install shows no switcher and keeps its answers (`I/GoldenApiParityTest.php::testTheAnswersAreThe015Ones`: the 0.11 answers plus exactly `state`, `dates_tentative`, `is_default` at the end of the event; homes unchanged).

## Community branding and each event's branding (0.16)

- The owner's acceptance case — a fictional community with its own Halloween branding (ghost headline, trick-or-treat intro, colours, About text) and Halloween, Christmas lights and a yard sale open at once: the lights' and the sale's map, About, sign-in, My house, legal pages and What's new carry no Halloween word (ghost, trick-or-treat, treats, candy, scare, pumpkin, 🎃, 👻) outside the switcher's tab labels, while Halloween keeps its branding: `I/PerEventBrandingTest.php::testTheOtherEventsCarryNoHalloweenWordWhileHalloweenKeepsItsBranding`; the same for emails (sign-in names only the community, a claim names its event and address, a review request the page's event): `::testEmailsNameTheEventTheyConcernAndCarryNoOtherEventsWords`; on the fictional lights demo in a browser: `E/events.spec.mjs` "each event has its own branding…", and `E/season.spec.mjs` (the demo's Halloween branding stays off the lights and the sale it opens).
- Halloween looks exactly as before after the upgrade from 0.15 and from 0.9: `I/EventBrandingMigrationTest.php` (2 tests; the Halloween pages, /theme.css and /api/community compared with `tests/fixtures/golden/0.15.0-beta.1/branding-halloween.json`, captured from 0.15.0-beta.1 on the same data; down and up again); `I/GoldenApiParityTest.php` (the 0.9, 0.11 and 0.15 answers unchanged).
- The 0.9 image after an image-only rollback: the stored community keys follow the Halloween it shows (`I/PerEventBrandingTest.php::testTheKeysThe09ImageReadsFollowTheHalloweenItShows`); the drill checks the 0.9 page shows the custom headline (step 5).
- Organizer → Events branding (strict checks, empty is the type default, kept when a client leaves it out, next year keeps it, BrandPalette per event, the draft preview): `::testOrganizersSetAnEventsBrandingUnderEvents`; `U/Event/EventBrandingTest.php` (4 tests); the community console's own fields: `::testTheCommunityConsoleKeepsOnlyTheCommunitysOwnFields`; community.yaml: `::testCommunityYamlBrandsTheFirstEventAndAnExplicitEventBrandingWins`.
- What's new without operator words: `I/WhatsNewTest.php`.
- The organizer console is the community's (D-20261031-52): every console page's chrome (mark, icon, colours, title) is the community's with no Halloween word or emoji, the data of an event names its event and type, the draft preview uses its own colours: `I/OrganizerConsoleNeutralTest.php` (2 tests); in a browser on the fictional lights demo (whose default is the lights and whose Halloween has its own branding), in light and dark, no word, emoji or colour of the default event or of Halloween outside an event's own section on Overview, Community, Boundary, Addresses, Review requests, Audit and Events: `E/events.spec.mjs` "the organizer console names the community and uses its own colours…".

## Hidden events (0.17)

The owner's report on 2026-11-01: closing Halloween showed 2027 to visitors at once; next year's event should stay
hidden while residents get ready ([D-20261101-01](../../DECISIONS.md), -02, -03).

- Closing creates next year's event hidden, and no public surface shows it — `/api/events`, the switcher, `/api/event`,
  `/api/community`, `/api/homes`, `/api/homes/{id}`, route plans and claim requests answer 404 like an unknown id;
  `/`, `/about`, a shared stop list and `/theme.css` with `e=` show the default event and never name it; the thank-you
  text names no hidden dates and the card keeps "Get my house ready" (to My house) without "See <event>":
  `I/HiddenNextEventTest.php::testClosingCreatesNextYearsEventHiddenFromEveryPublicSurface`; `E/season.spec.mjs` (no
  2027 tab after closing, the API 404s, its link shows the default event).
- Residents see it in My house only (401 for a visitor who is not signed in, whatever the id): its tab "Getting
  ready", the notice, its colours (`/me/theme.css`, residents only), "List for <event>", "Use last year's details",
  edits; nothing public, the legacy row untouched:
  `I/HiddenNextEventTest.php::testResidentsGetReadyForAHiddenEventInMyHouse`; `E/season.spec.mjs` (the resident lists
  for next year with last year's details on the hidden event).
- Organizer transitions — announce (confirmed; upcoming with its tentative dates, the tab and "See Halloween 2027"
  appear), hide again, open directly (homes on the map), refused for open and closed events, preview, audit:
  `I/HiddenNextEventTest.php::testTheOrganizerAnnouncesHidesAndOpensAHiddenEvent`; `E/season.spec.mjs` (announce →
  the tab appears), `E/events.spec.mjs` (closing the sale creates a hidden one; announcing it).
- Create hidden, draft → hidden, one current event per type counting hidden events, a hidden event never the default
  (the default passes on when an announced default is hidden again):
  `I/HiddenNextEventTest.php::testAnEventCanBeCreatedOrDraftedHiddenAndOneCurrentPerTypeCountsHiddenEvents`;
  `U/Event/EventStatesTest.php` (what each state allows).
- Races and the database without the app: `I/EventLifecycleRaceTest.php::testClosingWhileANextYearDraftIsHiddenAtOnceLeavesOneHiddenHalloween`,
  `::testConcurrentAnnouncesOfAHiddenEventHaveOneWinner`, `::testTheDatabaseCountsAHiddenEventAsTheCurrentOneOfItsType`,
  `::testAHiddenEventIsNeverTheDefaultEvenWithoutTheApp`.
- Moderation reaches a hidden event's listings: `I/HiddenNextEventTest.php::testAModerationHoldReachesAHiddenEventsListing`.
- The migration is additive (upcoming events keep their state; down makes hidden events drafts):
  `I/HiddenEventMigrationTest.php::testUpKeepsEveryEventAndDownMakesHiddenEventsDrafts`; the drill (steps 4b and 6:
  hidden across the image rollback to 0.9, announced afterwards), `U/Deploy/RollbackDrillTest.php::testTheDrillChecksNextYearsEventStaysHiddenUntilAnnounced`.

## Known limitations at 0.17.0-beta.1

- A resident who has not claimed a home yet cannot list a new one for a hidden event: the map's "List your home" form
  is public and lists for the event its page shows. Residents with claimed homes get ready in My house.

## Known limitations at 0.16.0-beta.1

- An organizer's own reminders (Community & branding) show for every event; each kind of event's built-in list applies only while they are empty.
- Branding changed on the 0.9 image after an image-only rollback is replaced by the events' own when 0.16 runs again.

## Known limitations at 0.15.0-beta.1

- Halloween's filter chips, time picker and card hearts are 40 px high (44 px on the other types), kept for parity.
- A lights home has one set of hours for all its nights in the forms (the data model takes hours per night).
- The contact check does not read numbers written as words; a range starting at 200 or more ("500-1000") is still refused as a phone number.
- Screenshots are compared by hand, not by a test.
- Emailing residents about a new event, and retiring the legacy `listings` table and its triggers, are later work (ROADMAP).
- After an image-only rollback the 0.9 release shows one Halloween (the newest open or closed); the 0.10–0.14 images are reached by restore, not by image rollback (D-20261031-41).
- Events are ordered default first, then by date; there is no organizer-chosen order of the switcher's tabs.
