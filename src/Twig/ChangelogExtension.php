<?php

declare(strict_types=1);

namespace App\Twig;

use App\About\ChangelogReader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @module Twig/ChangelogExtension
 * @description latest_changes(n): the newest releases from CHANGELOG.md for the "What's new" dialog.
 *
 * @dependencies About/ChangelogReader
 */
final class ChangelogExtension extends AbstractExtension
{
    public function __construct(private readonly ChangelogReader $reader)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('latest_changes', $this->reader->latest(...))];
    }
}
