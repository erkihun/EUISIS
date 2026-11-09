<?php

declare(strict_types=1);

use App\Casts\RichTextHtml;
use App\Rules\RichTextContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

function richTextErrors(mixed $value, RichTextContent|array $rules): array
{
    return Validator::make(['body' => $value], ['body' => is_array($rules) ? $rules : [$rules]])->errors()->get('body');
}

it('rejects editor placeholders as empty when required', function (mixed $value): void {
    expect(richTextErrors($value, RichTextContent::required()))->toBe(['The body field is required.']);
})->with([[null], [''], ['<p><br></p>'], ['<p>&nbsp;</p>'], ['<p></p><p><br></p>']]);

it('lets an optional field be empty', function (mixed $value): void {
    expect(richTextErrors($value, ['nullable', new RichTextContent]))->toBe([]);
})->with([[null], [''], ['<p><br></p>']]);

it('counts the limit in characters a reader sees', function (): void {
    $ten = '<p><strong>ሰላም</strong> <em>ዓለም</em> abc</p>';

    expect(richTextErrors($ten, RichTextContent::required(maxLength: 11)))->toBe([])
        ->and(richTextErrors($ten, RichTextContent::required(maxLength: 10)))->toBe(['The body may not be longer than 10 characters.']);
});

it('caps the stored markup however short the text', function (): void {
    config(['richtext.max_html_bytes' => 200]);
    $padded = '<p>'.str_repeat('<strong></strong>', 20).'x</p>';

    expect(richTextErrors($padded, RichTextContent::required()))->toHaveCount(1);
});

it('rejects values that are not text', function (): void {
    expect(richTextErrors(['<p>x</p>'], RichTextContent::required()))->toBe(['The body must be text.']);
});

it('shows Amharic messages in Amharic', function (): void {
    app()->setLocale('am');

    expect(richTextErrors('<p><br></p>', RichTextContent::required())[0])->toContain('አስፈላጊ ነው');
});

it('sanitizes on every write through the cast and stores empty as null', function (): void {
    $model = new class extends Model
    {
        protected $guarded = [];

        protected function casts(): array
        {
            return ['body' => RichTextHtml::class];
        }
    };

    $model->body = '<p onclick="x()">ok<script>alert(1)</script></p>';
    expect($model->getAttributes()['body'])->toBe('<p>ok</p>');

    $model->body = '<p><br></p>';
    expect($model->getAttributes()['body'])->toBeNull();

    $model->setRawAttributes(['body' => "Legacy plain text\nkept as stored"]);
    expect($model->body)->toBe("Legacy plain text\nkept as stored");
});
