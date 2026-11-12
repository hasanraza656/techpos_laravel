<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => 'required|uuid', 'branch' => 'required|uuid', 'warehouse' => 'required|uuid', 'shift' => 'required|uuid', 'customer' => 'nullable|uuid',
            'lines' => 'required|array|min:1|max:200', 'lines.*' => 'required|array:product,quantity,discount,price_override,expected_unit_price', 'lines.*.product' => 'required|uuid|distinct',
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,7}(?:\.\d{1,3})?$/'], 'lines.*.discount' => ['sometimes', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'lines.*.price_override' => ['sometimes', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'], 'lines.*.expected_unit_price' => ['sometimes', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'cart_discount' => ['sometimes', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'], 'expected_total' => ['sometimes', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'payments' => 'required|array|min:1|max:10', 'payments.*' => 'required|array:method,amount,reference', 'payments.*.method' => 'required|in:cash,external_card,bank_transfer',
            'payments.*.amount' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'], 'payments.*.reference' => 'nullable|string|max:120', 'note' => 'nullable|string|max:1000',
            'channel' => 'sometimes|in:online,offline', 'device_identifier' => 'required_if:channel,offline|required_with:local_sequence|nullable|uuid', 'local_sequence' => 'required_if:channel,offline|required_with:device_identifier|nullable|integer|min:1|max:9007199254740991',
            'payload_version' => 'sometimes|integer|in:1', 'client_occurred_at' => 'required_if:channel,offline|nullable|date|before_or_equal:'.now()->addMinutes(5)->toIso8601String(),
            'cashier' => 'required_with:device_identifier|nullable|uuid',
        ];
    }
}
