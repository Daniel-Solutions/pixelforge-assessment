<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CreditPackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BuyCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'package' => ['required', 'integer', Rule::enum(CreditPackage::class)],
            'purchase_token' => ['required', 'uuid'],
        ];
    }

    public function package(): CreditPackage
    {
        return CreditPackage::from((int) $this->validated('package'));
    }

    public function purchaseToken(): string
    {
        return (string) $this->validated('purchase_token');
    }
}
