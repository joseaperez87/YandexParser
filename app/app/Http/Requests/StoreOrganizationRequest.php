<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\YandexMaps\UrlResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class StoreOrganizationRequest extends FormRequest
{
    private ?array $resolved = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'url' => trim((string) $this->input('url')),
        ]);
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $this->resolved = app(UrlResolver::class)->resolve((string) $this->input('url'));
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('url', $exception->getMessage());
            }
        });
    }

    public function canonicalUrl(): string
    {
        return $this->resolved['url'] ?? (string) $this->input('url');
    }

    public function businessId(): string
    {
        return $this->resolved['business_id'] ?? '';
    }
}
