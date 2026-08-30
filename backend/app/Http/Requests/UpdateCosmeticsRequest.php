<?php

namespace App\Http\Requests;

use App\Enums\CosmeticSlot;
use App\Models\Cosmetic;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCosmeticsRequest extends FormRequest
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
            'equipped' => ['present', 'array'],
            'equipped.*' => ['nullable', 'integer'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $equipped = $this->input('equipped');

            if (! is_array($equipped)) {
                return;
            }

            $slots = CosmeticSlot::values();

            // starter cosmetics (owned implicitly) + everything this user has
            // actually unlocked, keyed by id.
            $ownable = Cosmetic::query()
                ->where(fn ($q) => $q
                    ->where('source', 'starter')
                    ->orWhereHas('owners', fn ($q) => $q->whereKey($this->user()->id)))
                ->get()
                ->keyBy('id');

            foreach ($equipped as $slot => $id) {
                if (! in_array($slot, $slots, true)) {
                    $validator->errors()->add("equipped.{$slot}", 'Unknown cosmetic slot.');

                    continue;
                }

                if ($id === null) {
                    continue;
                }

                $cosmetic = $ownable->get((int) $id);

                if ($cosmetic === null) {
                    $validator->errors()->add("equipped.{$slot}", "You don't own that item.");
                } elseif ($cosmetic->slot->value !== $slot) {
                    $validator->errors()->add("equipped.{$slot}", "That item can't be equipped in the {$slot} slot.");
                }
            }
        });
    }
}
