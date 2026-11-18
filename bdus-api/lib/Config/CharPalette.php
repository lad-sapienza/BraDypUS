<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Config;

/**
 * The `char_palette` setting of a long_text / md field: the buttons of the strip
 * under the editor that insert a character (or a short text) at the cursor.
 *
 * Clients always get a list of `{label, value}`. In the configuration the setting
 * is the NAME of a char map: one of the built-in presets (PRESETS) or one of the
 * application's own (`bdus_cfg_app.char_maps`, passed as $custom). A char map is
 * written as one of:
 *
 *   "α β γ"                                characters separated by spaces
 *   ["α", "β", {"label": "ᾱ", "value": "ᾱ"}]   a list (string or {label?, value})
 *   {"alpha": "α", "eta": "η"}             a map label → value
 *
 * A field may also carry such a char map inline instead of a name (what the first
 * version of the setting did): still understood.
 */
final class CharPalette
{
  public const MAX_ITEMS = 300;
  public const MAX_CUSTOM = 50;
  private const MAX_LABEL = 24;
  private const MAX_VALUE = 80;
  /** What a char map name looks like; a string that does is never read as characters. */
  private const NAME = '/^[a-z][a-z0-9-]{1,39}$/';

  /** Built-in presets: characters separated by spaces. */
  public const PRESETS = [
    'greek-polytonic' =>
      'ά ὰ ᾶ ἀ ἁ ἄ ἅ ἂ ἃ ἆ ἇ ᾳ ᾴ ᾲ ᾷ ᾱ ᾰ έ ὲ ἐ ἑ ἔ ἕ ἒ ἓ '
      . 'ή ὴ ῆ ἠ ἡ ἤ ἥ ἢ ἣ ἦ ἧ ῃ ῄ ῂ ῇ ί ὶ ῖ ἰ ἱ ἴ ἵ ἲ ἳ ἶ ἷ ϊ ΐ ῒ ῗ '
      . 'ό ὸ ὀ ὁ ὄ ὅ ὂ ὃ ύ ὺ ῦ ὐ ὑ ὔ ὕ ὒ ὓ ὖ ὗ ϋ ΰ ῢ ῧ ώ ὼ ῶ ὠ ὡ ὤ ὥ ὢ ὣ ὦ ὧ ῳ ῴ ῲ ῷ '
      . 'ῥ ῤ · ; ‘ ’',
  ];

  /**
   * @param mixed                $raw    the field's `char_palette`
   * @param array<string, mixed> $custom the application's own char maps, by name
   * @return array<int, array{label: string, value: string}>|null  null when there is nothing to show
   */
  public static function normalize(mixed $raw, array $custom = []): ?array
  {
    if (is_string($raw)) {
      $name = trim($raw);
      if (array_key_exists($name, $custom)) {
        return self::normalize($custom[$name]);      // a custom map is never a reference to another
      }
      if (isset(self::PRESETS[$name])) {
        $raw = self::PRESETS[$name];
      } elseif (preg_match(self::NAME, $name)) {
        return null;                                  // a name that does not exist (any more)
      } else {
        $raw = $name;
      }
      $raw = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if (!is_array($raw)) {
      return null;
    }

    $items = [];
    $isList = array_is_list($raw);
    foreach ($raw as $k => $v) {
      if ($isList && is_array($v)) {
        $value = $v['value'] ?? null;
        $label = $v['label'] ?? $value;
      } elseif ($isList) {
        $value = $v;
        $label = $v;
      } else {
        $label = $k;
        $value = $v;
      }
      if (!is_string($value) || $value === '' || !is_string($label) && !is_int($label)) {
        continue;
      }
      $label = (string) $label;
      if ($label === '' || mb_strlen($label) > self::MAX_LABEL || mb_strlen($value) > self::MAX_VALUE) {
        continue;
      }
      $items[] = ['label' => $label, 'value' => $value];
      if (count($items) >= self::MAX_ITEMS) {
        break;
      }
    }

    return $items ?: null;
  }

  /**
   * The built-in presets, as buttons: `name => [{label, value}, ...]`.
   *
   * @return array<string, array<int, array{label: string, value: string}>>
   */
  public static function systemMaps(): array
  {
    $out = [];
    foreach (array_keys(self::PRESETS) as $name) {
      $out[$name] = self::normalize($name) ?? [];
    }
    return $out;
  }

  /**
   * Checks the application's own char maps before they are saved.
   *
   * @param  array<string, mixed> $maps name => list | map | string
   * @return array<string, mixed> the maps to store (same shape, names kept in order)
   * @throws \InvalidArgumentException with a message that names the offending map
   */
  public static function validateCustom(array $maps): array
  {
    if (count($maps) > self::MAX_CUSTOM) {
      throw new \InvalidArgumentException('too many char maps (max ' . self::MAX_CUSTOM . ')');
    }
    $clean = [];
    foreach ($maps as $name => $raw) {
      $name = (string) $name;
      if (!preg_match(self::NAME, $name)) {
        throw new \InvalidArgumentException("{$name}: a name is 2-40 characters: lowercase letters, digits and hyphens, starting with a letter");
      }
      if (isset(self::PRESETS[$name])) {
        throw new \InvalidArgumentException("{$name}: this name belongs to a built-in preset");
      }
      if (is_string($raw)) {
        $raw = trim($raw);
      }
      // A char map is not a reference: a bare name here would be read as characters, not followed.
      if (is_string($raw) && (isset(self::PRESETS[$raw]) || preg_match(self::NAME, $raw))) {
        throw new \InvalidArgumentException("{$name}: write the characters, not the name of another map");
      }
      if (!is_string($raw) && !is_array($raw) || self::normalize($raw) === null) {
        throw new \InvalidArgumentException("{$name}: no usable characters");
      }
      $clean[$name] = $raw;
    }
    return $clean;
  }
}
