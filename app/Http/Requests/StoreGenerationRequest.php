<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\GenerationInput;
use App\Enums\Quality;
use App\Enums\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own rules so we never send a request it would reject with 422.
 */
class StoreGenerationRequest extends FormRequest
{
    public const int MAX_PROMPT_CHARACTERS = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

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
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['present', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'Use at most :max reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.*.required' => 'Remove empty reference image fields.',
        ];
    }

    public function toInput(): GenerationInput
    {
        /** @var array{prompt: string, size: string, quality: string, reference_images: array<int, string>} $validated */
        $validated = $this->validated();

        return new GenerationInput(
            prompt: $validated['prompt'],
            size: Size::from($validated['size']),
            quality: Quality::from($validated['quality']),
            referenceImages: array_values($validated['reference_images']),
        );
    }
}
