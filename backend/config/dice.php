<?php

declare(strict_types=1);

return [
    'max_expression_length' => (int) env('DICE_MAX_EXPRESSION_LENGTH', 200),
    'max_total_dice' => (int) env('DICE_MAX_TOTAL_DICE', 100),
    'min_sides' => (int) env('DICE_MIN_SIDES', 2),
    'max_sides' => (int) env('DICE_MAX_SIDES', 1000),
];
