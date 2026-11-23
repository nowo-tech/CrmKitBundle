<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

/**
 * The icons of the kit's own actions (0.8.1). Every action either uses a canonical UiKit kind (`mark_done`, `cancel`,
 * `move`, `download`… drawn from UiKit's map, so the host's `nowo_ui_kit.action_icons` applies) or one of these names.
 * A name is a canonical UiKit icon (`sort_asc`, `sort_desc`) or a bare Bootstrap Icons name (UiKit's default set; with
 * `icon_set: ux_icon` it is drawn as `bi:<name>`). Names that exist in no icon set (`custom`, `chart`, `merge`) made
 * `ux_icon` throw, so the page answered 500; tests/Unit/Infrastructure/UiIconsTest checks every name below against
 * the Bootstrap Icons drawings in tests/Fixtures/icons/bi and the pages render with a strict `ux_icon`. UiKit has no
 * kind for the five Bootstrap names, so `tabler-icons` draws an empty glyph and `svg_inline` the generic eye for them
 * (UPGRADING, "To 0.8.1").
 */
final class UiIcons
{
    /** A stage one place up / down (canonical UiKit icons: an arrow). */
    public const STAGE_UP   = 'sort_asc';
    public const STAGE_DOWN = 'sort_desc';

    /** The statistics of a funnel. */
    public const STATS = 'bar-chart';

    /** The board of a funnel. */
    public const BOARD = 'kanban';

    /** Merge two records. */
    public const MERGE = 'union';

    /** Make a contact the primary contact of a company / clear it. */
    public const PRIMARY_CONTACT       = 'person-check';
    public const PRIMARY_CONTACT_CLEAR = 'person-dash';

    /** The bare Bootstrap Icons names above (each one has its drawing in tests/Fixtures/icons/bi). */
    public const BOOTSTRAP = [self::STATS, self::BOARD, self::MERGE, self::PRIMARY_CONTACT, self::PRIMARY_CONTACT_CLEAR];

    /** The canonical UiKit icon names above. */
    public const CANONICAL = [self::STAGE_UP, self::STAGE_DOWN];
}
