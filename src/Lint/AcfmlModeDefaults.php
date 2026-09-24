<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Lint;

/**
 * The translation preference ACFML assigns to each field type in the two
 * field-group modes that manage preferences themselves.
 *
 * Mirrors `ACFML\FieldGroup\ModeDefaults::MAP` (ACFML 5.0.0,
 * acfml/classes/FieldGroup/ModeDefaults.php). In `translation` and
 * `localization` mode, saving the field group in wp-admin rewrites every
 * field's `wpml_cf_preferences` to this value
 * (`SaveHooks::overwriteAllFieldPreferencesWithGroupMode()`). A JSON value
 * that differs is therefore not a choice: it holds until the next admin save
 * and then silently changes. ACFML passes each default through the
 * `acfml_field_group_mode_field_translation_preference` filter; a site that
 * uses it gets a false finding here, and the table cannot know about it. `advanced` (Expert) mode is the only one that
 * keeps per-field values, so it has no row here.
 */
final class AcfmlModeDefaults {

    public const TRANSLATION  = 'translation';
    public const LOCALIZATION = 'localization';

    private const COPY      = 1;
    private const TRANSLATE = 2;
    private const COPY_ONCE = 3;

    /** Types ACFML translates in both modes. */
    private const TRANSLATED_TYPES = ['text', 'textarea', 'url', 'wysiwyg', 'link'];

    /**
     * Types ACFML copies in `translation` mode and copies once in
     * `localization` mode. A type in neither list is Copy in both modes.
     */
    private const COPIED_TYPES = [
        'number', 'range', 'email', 'password', 'image', 'file', 'oembed', 'gallery',
        'select', 'checkbox', 'radio', 'button_group', 'true_false', 'google_map',
        'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'icon_picker',
        'group', 'repeater', 'flexible_content', 'clone',
        'post_object', 'page_link', 'relationship', 'taxonomy', 'user',
    ];

    /**
     * Types that hold no value; ACFML stores no preference for them
     * (`ModeValidity::NO_VALUE_TYPES`).
     */
    public const VALUELESS_TYPES = ['accordion', 'message', 'separator', 'tab'];

    /**
     * The preference ACFML writes for $type in $mode, or null when $mode does
     * not manage preferences (`advanced`) or $type holds no value. An
     * unknown type falls back to Copy, as ACFML's own lookup does
     * (`Obj::pathOr( self::COPY, … )`).
     */
    public static function preference(string $mode, string $type): ?int {
        if (in_array($type, self::VALUELESS_TYPES, true)) {
            return null;
        }
        if ($mode !== self::TRANSLATION && $mode !== self::LOCALIZATION) {
            return null;
        }
        if (in_array($type, self::TRANSLATED_TYPES, true)) {
            return self::TRANSLATE;
        }
        if ($mode === self::LOCALIZATION && in_array($type, self::COPIED_TYPES, true)) {
            return self::COPY_ONCE;
        }
        return self::COPY;
    }
}
