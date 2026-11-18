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
 * Accepted in the configuration, and always handed to clients as a list of
 * `{label, value}`:
 *
 *   "greek-polytonic"                      a built-in preset, by name
 *   "α β γ"                                characters separated by spaces
 *   ["α", "β", {"label": "ᾱ", "value": "ᾱ"}]   a list (string or {label?, value})
 *   {"alpha": "α", "eta": "η"}             a map label → value
 */
final class CharPalette
{
  public const MAX_ITEMS = 300;
  private const MAX_LABEL = 24;
  private const MAX_VALUE = 80;

  /** Built-in presets: characters separated by spaces. */
  public const PRESETS = [
    'greek-polytonic' =>
      'ά ὰ ᾶ ἀ ἁ ἄ ἅ ἂ ἃ ἆ ἇ ᾳ ᾴ ᾲ ᾷ ᾱ ᾰ έ ὲ ἐ ἑ ἔ ἕ ἒ ἓ '
      . 'ή ὴ ῆ ἠ ἡ ἤ ἥ ἢ ἣ ἦ ἧ ῃ ῄ ῂ ῇ ί ὶ ῖ ἰ ἱ ἴ ἵ ἲ ἳ ἶ ἷ ϊ ΐ ῒ ῗ '
      . 'ό ὸ ὀ ὁ ὄ ὅ ὂ ὃ ύ ὺ ῦ ὐ ὑ ὔ ὕ ὒ ὓ ὖ ὗ ϋ ΰ ῢ ῧ ώ ὼ ῶ ὠ ὡ ὤ ὥ ὢ ὣ ὦ ὧ ῳ ῴ ῲ ῷ '
      . 'ῥ ῤ · ; ‘ ’',
  ];

  /**
   * @return array<int, array{label: string, value: string}>|null  null when there is nothing to show
   */
  public static function normalize(mixed $raw): ?array
  {
    if (is_string($raw)) {
      $name = trim($raw);
      $raw  = isset(self::PRESETS[$name]) ? self::PRESETS[$name] : $name;
      $raw  = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
}
