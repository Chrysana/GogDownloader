<?php

namespace App\Tui;

/**
 * Colors used by the interface, as SGR parameters. Only the basic 16 colors are used
 * so the interface looks the same in every terminal.
 */
final class Theme
{
    public const BASE = '37;40';

    public const TITLE_BAR = '1;91;40';

    public const STATUS_BAR = '31;40';

    public const STATUS_BAR_OK = '32;40';

    public const STATUS_BAR_WARN = '33;40';

    public const MENU = '97;44';

    public const MENU_SELECTED = '30;42';

    public const MENU_SELECTED_UNFOCUSED = '30;46';

    public const HEADING = '1;97;40';

    public const LABEL = '37;40';

    public const TEXT = '37;40';

    public const DIM = '90;40';

    public const VALUE = '97;40';

    public const GOOD = '92;40';

    public const BAD = '91;40';

    public const WARN = '93;40';

    public const INFO = '96;40';

    public const BUTTON = '97;44';

    public const BUTTON_FOCUSED = '97;45';

    public const INPUT = '30;46';

    public const INPUT_FOCUSED = '30;106';

    /** hint text shown in an empty field: black italic, so it reads clearly but differs from a typed value */
    public const INPUT_PLACEHOLDER = '3;30;46';

    public const FOCUSED = '30;47';

    public const LIST_CURSOR = '30;46';

    public const LIST_CURSOR_UNFOCUSED = '30;47';

    public const LIST_HEADER = '1;93;40';

    public const PROGRESS_DONE = '30;42';

    public const PROGRESS_TODO = '37;100';

    public const MODAL = '97;44';

    public const MODAL_BORDER = '1;97;44';

    public const MODAL_TEXT = '97;44';
}
