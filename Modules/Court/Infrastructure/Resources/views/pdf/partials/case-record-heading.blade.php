{{-- A numbered section heading of the case record PDF (court::pdf.case-record). --}}
<div class="section{{ ! empty($break) ? ' break' : '' }}">
    <table class="section-head"><tr>
        <td class="n">{{ $n }}</td>
        <td class="gap"></td>
        <td class="t">{{ $title }}</td>
    </tr></table>
</div>
