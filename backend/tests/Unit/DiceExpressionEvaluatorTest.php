<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DiceExpressionEvaluator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiceExpressionEvaluatorTest extends TestCase
{
    public function test_it_evaluates_parenthesized_dice_arithmetic_and_records_kept_dice(): void
    {
        $result = $this->app->make(DiceExpressionEvaluator::class)->evaluate('2d6kh1 + (3 - 1)');

        self::assertSame('2d6kh1+(3-1)', $result['expression']);
        self::assertGreaterThanOrEqual(3, $result['total']);
        self::assertLessThanOrEqual(8, $result['total']);
        self::assertSame('add', $result['breakdown']['type']);
        self::assertSame('dice', $result['breakdown']['left']['type']);
        self::assertCount(2, $result['breakdown']['left']['dice']);
        self::assertCount(1, array_filter($result['breakdown']['left']['dice'], static fn (array $die): bool => $die['kept']));
    }

    #[DataProvider('invalidExpressions')]
    public function test_it_rejects_unsupported_or_unsafe_dice_syntax(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(DiceExpressionEvaluator::class)->evaluate($expression);
    }

    /** @return array<string, array{string}> */
    public static function invalidExpressions(): array
    {
        return [
            'exploding dice' => ['1d6!'],
            'rerolls' => ['1d6r1'],
            'comparisons' => ['1d6>3'],
            'missing dice count' => ['d6'],
            'too many dice' => ['101d6'],
            'too few sides' => ['1d1'],
            'too many sides' => ['1d1001'],
            'invalid keep count' => ['2d6kh3'],
            'unclosed group' => ['(1d6+2'],
        ];
    }
}
