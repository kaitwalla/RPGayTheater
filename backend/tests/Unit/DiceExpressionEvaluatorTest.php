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
        self::assertSame('group', $result['breakdown']['right']['type']);
        self::assertSame('dice', $result['breakdown']['left']['type']);
        self::assertCount(2, $result['breakdown']['left']['dice']);
        self::assertCount(1, array_filter($result['breakdown']['left']['dice'], static fn (array $die): bool => $die['kept']));
    }

    public function test_it_supports_unary_operands_subtraction_and_keep_lowest_dice(): void
    {
        $result = $this->app->make(DiceExpressionEvaluator::class)->evaluate('+2d6kl1--3');

        self::assertSame('+2d6kl1--3', $result['expression']);
        self::assertSame('subtract', $result['breakdown']['type']);
        self::assertSame('negate', $result['breakdown']['right']['type']);
        self::assertSame('kl', $result['breakdown']['left']['keep_mode']);
        self::assertSame(1, $result['breakdown']['left']['keep_count']);
        self::assertCount(1, array_filter($result['breakdown']['left']['dice'], static fn (array $die): bool => $die['kept']));
        self::assertSame(array_sum(array_column(array_filter($result['breakdown']['left']['dice'], static fn (array $die): bool => $die['kept']), 'value')) + 3, $result['total']);
    }

    public function test_it_honors_expression_dice_and_side_boundaries(): void
    {
        config()->set('dice.max_expression_length', 3);
        self::assertSame(2, $this->app->make(DiceExpressionEvaluator::class)->evaluate('1+1')['total']);
        $this->assertInvalidExpression('1+1+1');

        config()->set('dice.max_expression_length', 200);
        config()->set('dice.max_total_dice', 2);
        $twoDice = $this->app->make(DiceExpressionEvaluator::class)->evaluate('1d2+1d2');
        self::assertCount(1, $twoDice['breakdown']['left']['dice']);
        self::assertCount(1, $twoDice['breakdown']['right']['dice']);
        $this->assertInvalidExpression('1d2+1d2+1d2');

        config()->set('dice.min_sides', 2);
        config()->set('dice.max_sides', 6);
        self::assertSame(1, $this->app->make(DiceExpressionEvaluator::class)->evaluate('1d2')['breakdown']['count']);
        self::assertSame(6, $this->app->make(DiceExpressionEvaluator::class)->evaluate('1d6')['breakdown']['sides']);
        $this->assertInvalidExpression('1d7');
    }

    #[DataProvider('invalidExpressions')]
    public function test_it_rejects_unsupported_or_unsafe_dice_syntax(string $expression): void
    {
        $this->assertInvalidExpression($expression);
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
            'zero keep count' => ['2d6kl0'],
            'missing keep count' => ['2d6kh'],
            'zero dice' => ['0d6'],
            'oversized number' => ['1000000000'],
            'arithmetic overflow' => ['999999999+999999999+3'],
            'unclosed group' => ['(1d6+2'],
        ];
    }

    private function assertInvalidExpression(string $expression): void
    {
        try {
            $this->app->make(DiceExpressionEvaluator::class)->evaluate($expression);
            self::fail("Expected '{$expression}' to be rejected.");
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
