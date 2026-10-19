<?php

declare(strict_types=1);

namespace App\Listing;

use App\Community\CommunityConfig;
use App\Shared\ValidationException;

/**
 * @module Listing/ListingDraft
 * @description Validates and normalizes resident-submitted listing fields (hours, offerings,
 *     sensory details, status, note). Disabled community features are forced off.
 *
 * @input array<string,mixed> raw form/JSON input, CommunityConfig
 * @output array<string,mixed> normalized listing draft
 * @dependencies Community/CommunityConfig, Shared/ValidationException
 */
final class ListingDraft
{
    public const STATUSES = ['active', 'paused', 'out_of_candy', 'done'];
    public const SCARE_LEVELS = ['gentle', 'mild', 'intense'];
    public const NOTE_MAX = 400;
    private const BOOLEANS = ['candy', 'nonfood', 'adult_drinks', 'low_noise', 'jump_scares', 'flashing_lights', 'loud_audio', 'stroller_friendly'];

    /**
     * @param array<string,mixed> $in
     *
     * @return array<string,mixed>
     */
    public static function fromInput(array $in, CommunityConfig $community, bool $partial = false, ?array $current = null): array
    {
        $base = $current ?? [
            'start' => $community->settings['event']['starts_at'], 'end' => $community->settings['event']['ends_at'],
            'candy' => false, 'nonfood' => false, 'adult_drinks' => false, 'low_noise' => false, 'jump_scares' => false,
            'flashing_lights' => false, 'loud_audio' => false, 'stroller_friendly' => false,
            'scare_level' => 'gentle', 'note' => '', 'status' => 'active', 'published' => true,
        ];
        $errors = [];
        $out = $base;

        foreach (['start', 'end'] as $f) {
            if (\array_key_exists($f, $in)) {
                $v = \is_string($in[$f]) ? substr(trim($in[$f]), 0, 5) : '';
                if (!preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $v)) {
                    $errors[$f] = 'Use a time like 18:00.';
                } else {
                    $out[$f] = $v;
                }
            } elseif (!$partial && null === $current) {
                $out[$f] = $base[$f];
            }
        }
        if (!isset($errors['start'], $errors['end']) && $out['end'] <= $out['start']) {
            $errors['end'] = 'End time must be later than the start time.';
        }

        foreach (self::BOOLEANS as $f) {
            if (\array_key_exists($f, $in)) {
                $out[$f] = filter_var($in[$f], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? false;
            }
        }
        if (\array_key_exists('published', $in)) {
            $out['published'] = filter_var($in['published'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? true;
        }
        $features = $community->features();
        if (!$features['candy']) {
            $out['candy'] = false;
        }
        if (!$features['nonfood_treats']) {
            $out['nonfood'] = false;
        }
        if (!$features['adult_refreshments']) {
            $out['adult_drinks'] = false;
        }
        if (!$out['candy'] && !$out['nonfood'] && !$out['adult_drinks']) {
            $errors['offerings'] = 'Choose at least one: candy, non-food treats or adult refreshments.';
        }

        if (\array_key_exists('scare_level', $in)) {
            if (!\in_array($in['scare_level'], self::SCARE_LEVELS, true)) {
                $errors['scare_level'] = 'Choose gentle, mild or intense.';
            } else {
                $out['scare_level'] = $in['scare_level'];
            }
        }
        if (\array_key_exists('status', $in)) {
            if (!\in_array($in['status'], self::STATUSES, true)) {
                $errors['status'] = 'Unknown status.';
            } else {
                $out['status'] = $in['status'];
            }
        }
        if (\array_key_exists('note', $in)) {
            $note = \is_string($in['note']) ? trim(preg_replace('~[\x00-\x09\x0B-\x1F\x7F]~u', '', $in['note']) ?? '') : '';
            if (mb_strlen($note) > self::NOTE_MAX) {
                $errors['note'] = 'Keep the note to 400 characters.';
            } else {
                $out['note'] = $note;
            }
        }

        if ([] !== $errors) {
            throw new ValidationException($errors);
        }

        return $out;
    }
}
