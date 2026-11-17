<?php

declare(strict_types=1);

namespace App\Pixelforge\Data;

/**
 * USD per 1M tokens, as exact decimal strings. A generation's rates are fixed when it is created.
 */
final readonly class Rates
{
    public function __construct(
        public string $inputText,
        public string $inputImage,
        public string $output,
        public string $reasoning,
    ) {}

    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            inputText: $rates['input_text_tokens'],
            inputImage: $rates['input_image_tokens'],
            output: $rates['output_tokens'],
            reasoning: $rates['reasoning_tokens'],
        );
    }

    /**
     * @return array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText,
            'input_image_tokens' => $this->inputImage,
            'output_tokens' => $this->output,
            'reasoning_tokens' => $this->reasoning,
        ];
    }
}
