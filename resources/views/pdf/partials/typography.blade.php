{{--
    Embedded document fonts for dompdf. Include this in every PDF template,
    before the template's own styles, and do not set font-family on body:

        @include('pdf.partials.typography', ['variant' => 'report'])   // or 'formal'

    See config/typography.php and docs/ui-typography.md.
--}}
<style>
{!! app(\App\Support\Typography\DocumentFonts::class)->css($variant ?? 'report') !!}
</style>
