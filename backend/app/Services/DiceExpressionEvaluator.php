<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

class DiceExpressionEvaluator
{
    private string $input = '';

    private int $offset = 0;

    private int $diceCount = 0;

    /**
     * @return array{expression: string, total: int, breakdown: array<string, mixed>}
     */
    public function evaluate(string $expression): array
    {
        $this->input = preg_replace('/\s+/', '', $expression) ?? '';
        $this->offset = 0;
        $this->diceCount = 0;
        if ($this->input === '' || strlen($this->input) > config('dice.max_expression_length')) {
            throw new InvalidArgumentException('A dice expression must be between 1 and '.config('dice.max_expression_length').' characters.');
        }
        $result = $this->sum();
        if ($this->offset !== strlen($this->input)) {
            throw new InvalidArgumentException('The dice expression contains unsupported syntax.');
        }

        return ['expression' => $this->input, 'total' => $result['total'], 'breakdown' => $result['breakdown']];
    }

    /**
     * @phpstan-impure
     *
     * @return array{total: int, breakdown: array<string, mixed>}
     */
    private function sum(): array
    {
        $result = $this->primary();
        while (($operator = $this->character()) !== null && in_array($operator, ['+', '-'], true)) {
            $this->offset++;
            $right = $this->primary();
            $total = $operator === '+' ? $result['total'] + $right['total'] : $result['total'] - $right['total'];
            $this->assertTotal($total);
            $result = ['total' => $total, 'breakdown' => ['type' => $operator === '+' ? 'add' : 'subtract', 'left' => $result['breakdown'], 'right' => $right['breakdown'], 'total' => $total]];
        }

        return $result;
    }

    /** @return array{total: int, breakdown: array<string, mixed>} */
    private function primary(): array
    {
        if ($this->character() === '(') {
            $this->offset++;
            $result = $this->sum();
            if ($this->character() !== ')') {
                throw new InvalidArgumentException('Every opening parenthesis must be closed.');
            }
            $this->offset++;

            return ['total' => $result['total'], 'breakdown' => ['type' => 'group', 'value' => $result['breakdown'], 'total' => $result['total']]];
        }
        if ($this->character() === '+') {
            $this->offset++;

            return $this->primary();
        }
        if ($this->character() === '-') {
            $this->offset++;
            $result = $this->primary();
            $total = -$result['total'];

            return ['total' => $total, 'breakdown' => ['type' => 'negate', 'value' => $result['breakdown'], 'total' => $total]];
        }
        $count = $this->number();
        if (strtolower($this->character() ?? '') !== 'd') {
            return ['total' => $count, 'breakdown' => ['type' => 'integer', 'value' => $count, 'total' => $count]];
        }
        $this->offset++;
        $sides = $this->number();
        $this->diceCount += $count;
        if ($count < 1 || $this->diceCount > config('dice.max_total_dice')) {
            throw new InvalidArgumentException('A roll may contain at most '.config('dice.max_total_dice').' dice.');
        }
        if ($sides < config('dice.min_sides') || $sides > config('dice.max_sides')) {
            throw new InvalidArgumentException('Dice must have between '.config('dice.min_sides').' and '.config('dice.max_sides').' sides.');
        }
        $mode = null;
        $keep = $count;
        $suffix = strtolower(substr($this->input, $this->offset, 2));
        if (in_array($suffix, ['kh', 'kl'], true)) {
            $this->offset += 2;
            $mode = $suffix;
            $keep = $this->number();
            if ($keep < 1 || $keep > $count) {
                throw new InvalidArgumentException('Keep counts must be between 1 and the number of dice rolled.');
            }
        }
        $rolled = [];
        for ($index = 0; $index < $count; $index++) {
            $rolled[$index] = random_int(1, $sides);
        }
        $keptIndices = array_keys($rolled);
        if ($mode !== null) {
            $ranked = $rolled;
            $mode === 'kh' ? arsort($ranked, SORT_NUMERIC) : asort($ranked, SORT_NUMERIC);
            $keptIndices = array_slice(array_keys($ranked), 0, $keep);
        }
        $keptLookup = array_flip($keptIndices);
        $dice = [];
        $total = 0;
        foreach ($rolled as $index => $value) {
            $kept = array_key_exists($index, $keptLookup);
            $dice[] = ['value' => $value, 'kept' => $kept];
            if ($kept) {
                $total += $value;
            }
        }

        return ['total' => $total, 'breakdown' => ['type' => 'dice', 'count' => $count, 'sides' => $sides, 'keep_mode' => $mode, 'keep_count' => $keep, 'dice' => $dice, 'total' => $total]];
    }

    private function number(): int
    {
        $start = $this->offset;
        while (($character = $this->character()) !== null && ctype_digit($character)) {
            $this->offset++;
        }
        $digits = substr($this->input, $start, $this->offset - $start);
        if ($digits === '' || strlen($digits) > 9) {
            throw new InvalidArgumentException('A dice expression must use whole numbers no greater than 999999999.');
        }

        return (int) $digits;
    }

    private function character(): ?string
    {
        return $this->offset < strlen($this->input) ? $this->input[$this->offset] : null;
    }

    private function assertTotal(int $total): void
    {
        if (abs($total) > 2000000000) {
            throw new InvalidArgumentException('The dice expression total is too large.');
        }
    }
}
