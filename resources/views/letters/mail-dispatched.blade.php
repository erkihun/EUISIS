@php
    /** @var string $institutionName */
    /** @var string $number */
    /** @var string $subjectLine */
    /** @var string $verificationCode */
    /** @var string $verifyUrl */
    /** @var string $locale */
    $t = fn (string $key, array $replace = []) => __('letters.mail.'.$key, $replace, $locale);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head><meta charset="UTF-8"><title>{{ $t('subject', ['institution' => $institutionName, 'number' => $number]) }}</title></head>
<body style="font-family: 'Noto Sans Ethiopic', 'Abyssinica SIL', Arial, sans-serif; color: #111; font-size: 15px; line-height: 1.6;">
    <p>{{ $t('greeting') }}</p>
    <p>{{ $t('body', ['institution' => $institutionName, 'number' => $number]) }}</p>
    <p><strong>{{ $t('subject_label') }}:</strong> {{ $subjectLine }}</p>
    <p>{{ $t('verify', ['code' => $verificationCode]) }}<br><a href="{{ $verifyUrl }}">{{ $verifyUrl }}</a></p>
    <p style="color: #555; font-size: 13px;">{{ $t('footer') }}</p>
</body>
</html>
