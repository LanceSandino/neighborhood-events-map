<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @module Twig/IconExtension
 * @description The app's drawn art in templates: icon('i-map') (public/assets/icons/icons.svg), brand_mark('brand-lights')
 *     (brand.svg, the mark of an event type or of the community) and hero_art('hero-lights') (heroes.svg). Each is an
 *     <svg> with aria-hidden and a <use> of the versioned sprite: art is decorative, the words beside it or the control's
 *     own label carry the meaning. reminder_icon(text, copy) gives a built-in reminder's icon and nothing for an
 *     organizer's own text, which is shown as written. A name outside the sprites' plain id shape draws nothing, so an
 *     id can never become markup.
 *
 * @dependencies Twig/AssetExtension, Twig
 */
final class IconExtension extends AbstractExtension
{
    public function __construct(private readonly AssetExtension $assets)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('icon', $this->icon(...), ['is_safe' => ['html']]),
            new TwigFunction('brand_mark', $this->brandMark(...), ['is_safe' => ['html']]),
            new TwigFunction('hero_art', $this->heroArt(...), ['is_safe' => ['html']]),
            new TwigFunction('reminder_icon', $this->reminderIcon(...), ['is_safe' => ['html']]),
        ];
    }

    public function icon(?string $name, string $class = ''): string
    {
        return $this->use('icons/icons.svg', $name, trim('icon '.$class));
    }

    public function brandMark(?string $name): string
    {
        return $this->use('icons/brand.svg', $name, 'mark');
    }

    public function heroArt(?string $name): string
    {
        return $this->use('icons/heroes.svg', $name, 'hero-art');
    }

    /** @param array<string,mixed> $copy the event type's copy (EventType::copy()) */
    public function reminderIcon(string $text, array $copy): string
    {
        $name = $copy['reminder_icons'][$text] ?? null;

        return \is_string($name) ? $this->icon($name, 'reminder-icon') : '';
    }

    private function use(string $sprite, ?string $name, string $class): string
    {
        if (null === $name || !preg_match('~^[a-z0-9]+(-[a-z0-9]+)*$~', $name)) {
            return '';
        }

        return sprintf('<svg class="%s" aria-hidden="true" focusable="false"><use href="%s#%s"></use></svg>', $class, $this->assets->assetPath($sprite), $name);
    }
}
