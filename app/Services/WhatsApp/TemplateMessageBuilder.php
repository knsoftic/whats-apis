<?php

namespace App\Services\WhatsApp;

use App\Models\MessageTemplate;
use Illuminate\Validation\ValidationException;

/**
 * Validates user-supplied template variables and builds the Meta
 * "components" array + a human-readable preview.
 *
 * Input shape:
 *   [
 *     'header' => ['1' => 'Ravi'] | ['link' => 'https://…/file.pdf', 'filename' => 'Invoice.pdf'],
 *     'body'   => ['1' => 'Ravi', '2' => '#1234']   (or named keys for NAMED templates),
 *     'buttons'=> [0 => 'ABC123']                    (dynamic URL suffix per button index),
 *   ]
 */
class TemplateMessageBuilder
{
    public const MAX_PARAM_LENGTH = 1024;

    /**
     * @param  array<string, mixed>  $input
     * @return array{components: array<int, array<string, mixed>>, preview: string, params: array<string, mixed>}
     */
    public function build(MessageTemplate $template, array $input): array
    {
        $errors = [];
        $components = [];
        $named = strtoupper((string) $template->parameter_format) === 'NAMED';

        // ---- Header ---------------------------------------------------------
        $headerFormat = $template->headerFormat();
        $headerPreview = null;

        if ($headerFormat === 'TEXT') {
            $placeholders = $template->headerPlaceholders();
            $values = $this->collect($placeholders, $input['header'] ?? [], 'header', $errors);

            if ($placeholders !== []) {
                $components[] = ['type' => 'header', 'parameters' => $this->textParameters($placeholders, $values, $named)];
            }

            $headerPreview = $this->render($template->header()['text'] ?? '', $values);
        } elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $link = trim((string) ($input['header']['link'] ?? ''));
            $mediaId = trim((string) ($input['header']['id'] ?? ''));

            if ($link === '' && $mediaId === '') {
                $errors['header.link'] = "This template needs a {$headerFormat} header: provide a public HTTPS link.";
            } elseif ($link !== '' && (! filter_var($link, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($link), 'https://'))) {
                $errors['header.link'] = 'The header media link must be a valid HTTPS URL.';
            } else {
                $kind = strtolower($headerFormat);
                $media = $mediaId !== '' ? ['id' => $mediaId] : ['link' => $link];

                if ($kind === 'document' && ! empty($input['header']['filename'])) {
                    $media['filename'] = mb_substr((string) $input['header']['filename'], 0, 240);
                }

                $components[] = ['type' => 'header', 'parameters' => [['type' => $kind, $kind => $media]]];
                $headerPreview = "[{$headerFormat}]";
            }
        } elseif ($headerFormat === 'LOCATION') {
            $errors['header'] = 'Location-header templates are not supported by this sender yet.';
        }

        // ---- Body -----------------------------------------------------------
        $bodyPlaceholders = $template->bodyPlaceholders();
        $bodyValues = $this->collect($bodyPlaceholders, $input['body'] ?? [], 'body', $errors);

        if ($bodyPlaceholders !== []) {
            $components[] = ['type' => 'body', 'parameters' => $this->textParameters($bodyPlaceholders, $bodyValues, $named)];
        }

        $bodyPreview = $this->render($template->body()['text'] ?? '', $bodyValues);

        // ---- Buttons (dynamic URL suffixes) ---------------------------------
        foreach ($template->dynamicUrlButtons() as $button) {
            $value = trim((string) ($input['buttons'][$button['index']] ?? ''));

            if ($value === '') {
                $errors["buttons.{$button['index']}"] = "Enter the URL variable for button “{$button['text']}”.";

                continue;
            }

            if (mb_strlen($value) > 2000 || preg_match('/\s/', $value)) {
                $errors["buttons.{$button['index']}"] = 'The button URL variable cannot contain spaces.';

                continue;
            }

            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => (string) $button['index'],
                'parameters' => [['type' => 'text', 'text' => $value]],
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $preview = trim(implode("\n", array_filter([
            $headerPreview,
            $bodyPreview,
            $template->footer()['text'] ?? null,
        ])));

        return [
            'components' => $components,
            'preview' => $preview,
            'params' => ['header' => $input['header'] ?? [], 'body' => $bodyValues, 'buttons' => $input['buttons'] ?? []],
        ];
    }

    /**
     * @param  array<int, string>  $placeholders
     * @param  array<string|int, mixed>  $given
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function collect(array $placeholders, array $given, string $section, array &$errors): array
    {
        $values = [];

        foreach ($placeholders as $key) {
            $value = trim((string) ($given[$key] ?? ''));

            if ($value === '') {
                $errors["{$section}.{$key}"] = "Variable {{{$key}}} in the {$section} is required.";

                continue;
            }

            // Meta rejects new lines, tabs and more than 4 consecutive spaces in parameters.
            if (preg_match('/[\r\n\t]/', $value) || str_contains($value, '     ')) {
                $errors["{$section}.{$key}"] = "Variable {{{$key}}} cannot contain new lines, tabs or more than 4 spaces in a row.";

                continue;
            }

            if (mb_strlen($value) > self::MAX_PARAM_LENGTH) {
                $errors["{$section}.{$key}"] = "Variable {{{$key}}} is too long.";

                continue;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param  array<int, string>  $placeholders
     * @param  array<string, string>  $values
     * @return array<int, array<string, string>>
     */
    private function textParameters(array $placeholders, array $values, bool $named): array
    {
        if (! $named) {
            // Positional {{1}}, {{2}} … must be sent in numeric order.
            usort($placeholders, fn ($a, $b) => (int) $a <=> (int) $b);
        }

        return array_map(function ($key) use ($values, $named) {
            $param = ['type' => 'text', 'text' => $values[$key] ?? ''];

            if ($named) {
                $param['parameter_name'] = $key;
            }

            return $param;
        }, $placeholders);
    }

    /**
     * @param  array<string, string>  $values
     */
    public function render(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', fn ($m) => $values[$m[1]] ?? $m[0], $text);
    }
}
