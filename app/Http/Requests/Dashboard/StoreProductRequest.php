<?php

namespace App\Http\Requests\Dashboard;

use App\Repricer\Catalog\Channel;
use App\Simulator\Engine\Bots\BotRegistry;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The add-product form. Money fields are integer cents. The pricing rule is validated exactly
 * like the rule editor (UpdatePricingRuleRequest); the client mirrors all of it
 * (resources/js/repricer/lib/product-validation.ts).
 */
class StoreProductRequest extends FormRequest
{
    public const MAX_COMPETITORS = 2;

    public function authorize(): bool
    {
        return Gate::allows('operate');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => strtoupper(trim($this->input('sku')))]);
        }
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = UpdatePricingRuleRequest::MAX_CENTS;

        return [
            'title' => ['required', 'string', 'min:3', 'max:80'],
            'sku' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9-]{1,31}$/', Rule::unique('products', 'sku')],
            'cost' => ['required', 'integer', 'min:0', 'max:'.$max],
            'fees' => ['required', 'integer', 'min:0', 'max:'.$max],
            'shipping' => ['required', 'integer', 'min:0', 'max:100000'],
            'price' => ['required', 'integer', 'min:1', 'max:'.$max],
            'channel' => ['sometimes', Rule::enum(Channel::class)],
            'competitors' => ['present', 'array', 'max:'.self::MAX_COMPETITORS],
            'competitors.*' => ['string', 'distinct', Rule::in(app(BotRegistry::class)->keys())],
        ] + UpdatePricingRuleRequest::ruleFields();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.regex' => 'Use 2–32 capital letters, digits and dashes (e.g. MUG-CER-12).',
            'sku.unique' => 'That SKU is already used (archived products keep theirs).',
            'competitors.max' => 'Pick at most '.self::MAX_COMPETITORS.' competitors.',
            'competitors.*.in' => 'Unknown competitor type.',
        ];
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

            $floor = (int) $this->input('floor');
            $ceiling = (int) $this->input('ceiling');
            $price = (int) $this->input('price');
            UpdatePricingRuleRequest::checkBounds($validator, $floor, $ceiling,
                (int) $this->input('cost'), (int) $this->input('fees'), (int) $this->input('min_margin'));

            UpdatePricingRuleRequest::checkStrategyFitsChannel($validator, (string) $this->input('strategy'),
                Channel::tryFrom((string) $this->input('channel', 'buybox')) ?? Channel::BuyBox);

            if ($validator->errors()->isEmpty() && ($price < $floor || $price > $ceiling)) {
                $validator->errors()->add('price', 'The starting price ('.Money::cents($price).') must be between the floor ('.Money::cents($floor).') and the ceiling ('.Money::cents($ceiling).').');
            }
        }];
    }
}
