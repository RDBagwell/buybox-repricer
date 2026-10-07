<?php

namespace App\Http\Requests\Dashboard;

use App\Repricer\Models\Product;
use App\Repricer\Rules\NoCompetitionAction;
use App\Repricer\Rules\Strategy;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Server-side validation for the rule editor. All money fields are integer cents.
 * The client mirrors these rules (resources/js/lib/rule-validation.ts) for instant feedback;
 * this is the one that counts.
 */
class UpdatePricingRuleRequest extends FormRequest
{
    public const MAX_CENTS = 10_000_000; // $100,000

    public function authorize(): bool
    {
        return Gate::allows('operate');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::ruleFields();
    }

    /**
     * The pricing-rule fields, shared with the add-product form (StoreProductRequest).
     *
     * @return array<string, mixed>
     */
    public static function ruleFields(): array
    {
        return [
            'strategy' => ['required', Rule::enum(Strategy::class)],
            'offset' => ['required', 'integer', 'min:0', 'max:10000'],
            'floor' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CENTS],
            'ceiling' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CENTS],
            'min_margin' => ['required', 'integer', 'min:0', 'max:'.self::MAX_CENTS],
            'max_step_pct' => ['required', 'integer', 'min:1', 'max:50'],
            'cooldown_sec' => ['required', 'integer', 'min:0', 'max:86400'],
            'min_competitor_rating' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'max_competitor_handling_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'no_competition' => ['sometimes', Rule::enum(NoCompetitionAction::class)],
        ];
    }

    /**
     * floor <= ceiling, and floor >= cost + fees + minimum margin (all integer cents).
     */
    public static function checkBounds(Validator $validator, int $floor, int $ceiling, int $cost, int $fees, int $minMargin): void
    {
        $marginFloor = $cost + $fees + $minMargin;

        if ($floor > $ceiling) {
            $validator->errors()->add('floor', 'The floor ('.Money::cents($floor).') must not be above the ceiling ('.Money::cents($ceiling).').');
        }
        if ($floor < $marginFloor) {
            $validator->errors()->add('floor', 'The floor ('.Money::cents($floor).') is below the margin floor ('.Money::cents($marginFloor).' = cost + fees + minimum margin).');
        }
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Product $product */
            $product = $this->route('product');
            self::checkBounds($validator, (int) $this->input('floor'), (int) $this->input('ceiling'),
                $product->cost->cents, $product->fees->cents, (int) $this->input('min_margin'));
        }];
    }
}
